<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Admin\Http\Controllers;

use Dmitryisaenko\LaraFoundry\ActivityLog\Facades\Activity;
use Dmitryisaenko\LaraFoundry\Admin\Http\Filters\AdminUsersFilter;
use Dmitryisaenko\LaraFoundry\Admin\Http\Requests\StoreUserRequest;
use Dmitryisaenko\LaraFoundry\Admin\Http\Requests\UpdateUserRequest;
use Dmitryisaenko\LaraFoundry\Admin\Http\Resources\AdminUserResource;
use Dmitryisaenko\LaraFoundry\Support\Pagination\HasPagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super-admin user management — the first populated operator-console screen
 * (phase 2.3).
 *
 * Reachable only through the `larafoundry.admin` gate (super-admin via
 * VisitorStatus). Extracted and hardened from the donor `Admin\UserController`
 * (recon §B): block now also invalidates the user's tracked sessions (finding
 * #4), and block/unblock/delete/undelete are all written to the activity log
 * (finding #8). Phase 3a adds operator email/phone verify overrides (audited, no
 * mail/SMS sent) and gates the personal columns (phone/sex/age) behind the
 * `larafoundry.admin.user_columns` opt-in so the default payload stays
 * privacy-clean.
 */
class UserController extends Controller
{
    use HasPagination;

    /**
     * The paginated, filtered user list.
     */
    public function index(Request $request): Response
    {
        $query = (new AdminUsersFilter($request))->apply($this->query());

        $users = $query
            ->withCount(['companies', 'ownedCompanies', 'employeeCompanies'])
            ->latest()
            ->paginate($this->perPage())
            ->withQueryString();

        $resource = $this->resource();

        return Inertia::render('Admin/Users/Index', [
            'users' => $resource::collection($users),
            'pagination' => $this->getPaginationData($users),
            // The sanitised opt-in columns the table should render (phase 3a) —
            // the SAME list the resource used to decide what to serialise, so the
            // headers/filters never drift from the payload.
            'userColumns' => AdminUserResource::enabledColumns(),
            'filters' => $request->only([
                'search', 'registered', 'emailVerified', 'status', 'recentActivity', 'locale', 'authType',
                'country', 'phoneVerified', 'sex', 'ageRange',
            ]),
        ]);
    }

    /**
     * The edit form for one user.
     */
    public function edit(int|string $user): Response
    {
        $target = $this->find($user);
        $resource = $this->resource();

        return Inertia::render('Admin/Users/Edit', [
            // The edit form always needs the personal columns (phone/sex/age),
            // even under the default privacy-clean `user_columns` — otherwise the
            // form prefills a blank phone and a save wipes the stored number.
            'user' => $resource::full($target),
        ]);
    }

    /**
     * The create form.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Users/Create');
    }

    /**
     * Persist a new user.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $model = $this->model();

        /** @var Model $user */
        $user = new $model;
        $user->fill($request->only(['name', 'lastname', 'email', 'phone', 'password', 'country']));

        // is_admin is a privilege column excluded from mass-assign by the trait,
        // so set it explicitly from the validated boolean (never blind-filled).
        if ($request->boolean('is_admin')) {
            $user->forceFill(['is_admin' => true]);
        }

        $user->save();

        $this->audit('admin.user.created', $user);

        return redirect()
            ->route('admin.users.index')
            ->with('success', __('larafoundry::admin.users.created'));
    }

    /**
     * Update an existing user.
     */
    public function update(UpdateUserRequest $request, int|string $user): RedirectResponse
    {
        $target = $this->find($user);

        $target->fill($request->only(['name', 'lastname', 'email', 'phone', 'country']));

        if ($request->filled('password')) {
            $target->fill(['password' => $request->input('password')]);
        }

        if ($request->has('is_admin')) {
            $target->forceFill(['is_admin' => $request->boolean('is_admin')]);
        }

        $target->save();

        $this->audit('admin.user.updated', $target);

        return redirect()
            ->route('admin.users.edit', $target->getKey())
            ->with('success', __('larafoundry::admin.users.updated'));
    }

    /**
     * AJAX search (JSON) for type-ahead in the console.
     */
    public function search(Request $request): JsonResponse
    {
        $users = (new AdminUsersFilter($request))->apply($this->query())
            ->limit(20)
            ->get();

        $resource = $this->resource();

        return response()->json([
            'users' => $resource::collection($users),
        ]);
    }

    /**
     * Block a user: set the blocking columns, kill their tracked sessions and
     * log it.
     *
     * Blocking alone is enforced by EnsureAccountIsActive on the user's next
     * request; clearing the tracked sessions removes the lingering device rows
     * so the block takes hold immediately rather than on next page load
     * (finding #4).
     */
    public function block(Request $request, int|string $user): RedirectResponse
    {
        $target = $this->find($user);

        // block_code is an unsignedTinyInteger (0-255); clamp so a host/API
        // caller cannot overflow the column (0 / out-of-range → no code).
        $code = (int) $request->integer('block_code', 0);
        $code = ($code > 0 && $code <= 255) ? $code : null;

        $target->forceFill([
            'user_blocked_at' => now(),
            'block_code' => $code,
            'user_blocked_status' => $request->input('reason'),
        ])->save();

        $this->purgeSessions($target);

        $this->audit('admin.user.blocked', $target, ['block_code' => $code]);

        return back()->with('success', __('larafoundry::admin.users.blocked'));
    }

    /**
     * Unblock a user.
     */
    public function unblock(int|string $user): RedirectResponse
    {
        $target = $this->find($user);

        $target->forceFill([
            'user_blocked_at' => null,
            'block_code' => null,
            'user_blocked_status' => null,
        ])->save();

        $this->audit('admin.user.unblocked', $target);

        return back()->with('success', __('larafoundry::admin.users.unblocked'));
    }

    /**
     * Soft-delete a user via the identity column and kill their sessions.
     *
     * This is the reversible operator delete (`user_deleted_at`); true deletion
     * (GDPR erasure) is a separate super-admin flow, not part of this phase
     * (finding #7).
     */
    public function destroy(int|string $user): RedirectResponse
    {
        $target = $this->find($user);

        $target->forceFill(['user_deleted_at' => now()])->save();

        $this->purgeSessions($target);

        $this->audit('admin.user.deleted', $target);

        return back()->with('success', __('larafoundry::admin.users.deleted'));
    }

    /**
     * Restore a soft-deleted user.
     */
    public function undelete(int|string $user): RedirectResponse
    {
        $target = $this->find($user);

        $target->forceFill(['user_deleted_at' => null])->save();

        $this->audit('admin.user.restored', $target);

        return back()->with('success', __('larafoundry::admin.users.restored'));
    }

    /**
     * Force-mark a user's email as verified (phase 3a).
     *
     * An operator override for support cases — it stamps `email_verified_at` and
     * audits it. Deliberately sends NO verification mail (the operator is asserting
     * the address, not asking the user to confirm it).
     */
    public function verifyEmail(int|string $user): RedirectResponse
    {
        $target = $this->find($user);

        $target->forceFill(['email_verified_at' => now()])->save();

        $this->audit('admin.user.email_verified', $target);

        return back()->with('success', __('larafoundry::admin.users.email_verified'));
    }

    /**
     * Clear a user's email verification (phase 3a). Audited; sends nothing.
     */
    public function unverifyEmail(int|string $user): RedirectResponse
    {
        $target = $this->find($user);

        $target->forceFill(['email_verified_at' => null])->save();

        $this->audit('admin.user.email_unverified', $target);

        return back()->with('success', __('larafoundry::admin.users.email_unverified'));
    }

    /**
     * Force-mark a user's phone as verified (phase 3a).
     *
     * SMS is out of scope for the core (decision 2026-07-10): this is a pure
     * operator override, no message is sent or re-sent. Stamps `phone_verified_at`
     * and audits it.
     */
    public function verifyPhone(int|string $user): RedirectResponse
    {
        $target = $this->find($user);

        $target->forceFill(['phone_verified_at' => now()])->save();

        $this->audit('admin.user.phone_verified', $target);

        return back()->with('success', __('larafoundry::admin.users.phone_verified'));
    }

    /**
     * Clear a user's phone verification (phase 3a). Audited; sends nothing.
     */
    public function unverifyPhone(int|string $user): RedirectResponse
    {
        $target = $this->find($user);

        $target->forceFill(['phone_verified_at' => null])->save();

        $this->audit('admin.user.phone_unverified', $target);

        return back()->with('success', __('larafoundry::admin.users.phone_unverified'));
    }

    /**
     * Write one super-admin action to the activity log.
     *
     * Centralises the admin audit shape (the `admin` log name, the `target_id`
     * property, the affected user as subject) so every console action records
     * consistently and a new audit field is added in one place. Geo is enqueued
     * (`geoSync: false`) so a slow geo provider never blocks an operator action.
     *
     * @param  array<string, mixed>  $properties
     */
    protected function audit(string $description, Model $target, array $properties = []): void
    {
        Activity::log(
            description: $description,
            logName: 'admin',
            properties: array_merge(['target_id' => $target->getKey()], $properties),
            subject: $target,
            geoSync: false,
        );
    }

    /**
     * Delete the user's tracked session rows, if the model exposes them.
     */
    protected function purgeSessions(Model $user): void
    {
        if (method_exists($user, 'sessions')) {
            $user->sessions()->delete();
        }
    }

    /**
     * @return Builder<Model>
     */
    protected function query()
    {
        return $this->model()::query();
    }

    protected function find(int|string $id): Model
    {
        return $this->query()->findOrFail($id);
    }

    /**
     * @return class-string<Model>
     */
    protected function model(): string
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        return $model;
    }

    /**
     * The resource class used to serialise users for the console.
     *
     * Host seam (phase 7): a host that needs extra user-list columns points
     * `larafoundry.admin.user_resource` at an {@see AdminUserResource} subclass
     * overriding `extra()`. Defaults to the core resource. Validated to be an
     * AdminUserResource so a mis-set config cannot swap in an arbitrary class.
     *
     * @return class-string<AdminUserResource>
     */
    protected function resource(): string
    {
        /** @var mixed $class */
        $class = config('larafoundry.admin.user_resource', AdminUserResource::class);

        return (is_string($class) && is_a($class, AdminUserResource::class, true))
            ? $class
            : AdminUserResource::class;
    }

    protected function perPage(): int
    {
        return (int) config('larafoundry.admin.users_per_page', 21);
    }
}
