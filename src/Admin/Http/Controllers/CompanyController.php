<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Admin\Http\Controllers;

use Dmitryisaenko\LaraFoundry\ActivityLog\Facades\Activity;
use Dmitryisaenko\LaraFoundry\Admin\Http\Filters\AdminCompaniesFilter;
use Dmitryisaenko\LaraFoundry\Admin\Http\Resources\AdminCompanyResource;
use Dmitryisaenko\LaraFoundry\Admin\Policies\CompanyPolicy;
use Dmitryisaenko\LaraFoundry\Auth\Models\UserSession;
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
 * Super-admin company management — the second populated operator-console screen
 * (phase 3.3), built on the same pattern as {@see UserController} (phase 2.3).
 *
 * Reachable only through the `larafoundry.admin` gate (super-admin via
 * VisitorStatus). The donor's admin company screen was read-only (recon §E):
 * index + search, no way to act on a company. This keeps the read-only list and
 * detail, and ADDS the company-level block/unblock the donor lacked — a cascade
 * that takes the whole team offline (enforced at EnsureActiveTenant), audited to
 * the activity log and accompanied by an immediate session purge so the block
 * bites at once rather than on the members' next company switch.
 *
 * Subscription state is shown read-only (from the billing columns, phase 3.1);
 * managing a plan/period is the paid billing add-on, not the core.
 */
class CompanyController extends Controller
{
    use HasPagination;

    public function __construct(
        protected CompanyPolicy $policy,
    ) {}

    /**
     * The paginated, filtered company list.
     */
    public function index(Request $request): Response
    {
        $query = (new AdminCompaniesFilter($request))->apply($this->query());

        $companies = $query
            ->with('createdBy:id,name,lastname,email')
            ->withCount('users')
            ->latest()
            ->paginate($this->perPage())
            ->withQueryString();

        return Inertia::render('Admin/Companies/Index', [
            'companies' => AdminCompanyResource::collection($companies),
            'pagination' => $this->getPaginationData($companies),
            'filters' => $request->only([
                'search', 'country', 'dateFrom', 'dateTo', 'subscriptionStatus', 'blockState',
            ]),
        ]);
    }

    /**
     * The read-only detail for one company (members + subscription snapshot).
     */
    public function show(string $company): Response
    {
        $target = $this->find($company);

        $target->load('createdBy:id,name,lastname,email')->loadCount('users');

        return Inertia::render('Admin/Companies/Show', [
            'company' => new AdminCompanyResource($target),
        ]);
    }

    /**
     * AJAX search (JSON) for type-ahead in the console.
     */
    public function search(Request $request): JsonResponse
    {
        $companies = (new AdminCompaniesFilter($request))->apply($this->query())
            ->with('createdBy:id,name,lastname,email')
            ->withCount('users')
            ->limit(20)
            ->get();

        return response()->json([
            'companies' => AdminCompanyResource::collection($companies),
        ]);
    }

    /**
     * Block a company: set the block columns, drop members' active-company
     * bookkeeping for it, and audit the action.
     *
     * Enforcement of the block lives at the tenancy boundary (EnsureActiveTenant
     * denies the tenant screens whenever the active company is blocked) — that is
     * what actually keeps a blocked company's members out, on their next request.
     * The purge here is NOT a logout (it does not touch the auth session); it
     * clears the tracked `user_sessions.active_company_id` rows pointing at THIS
     * company so that those devices stop resolving to it, and EnsureActiveTenant
     * moves them to one of their other companies (or the blocked screen) right
     * away. Rows scoped to other companies are untouched — a member of two
     * companies keeps the other one.
     */
    public function block(Request $request, string $company): RedirectResponse
    {
        abort_unless($this->policy->block($request->user()), 403);

        $target = $this->find($company);

        $reason = $request->input('reason');
        $reason = is_string($reason) && $reason !== '' ? $reason : null;

        $target->forceFill([
            'company_blocked_at' => now(),
            'company_blocked_reason' => $reason,
        ])->save();

        $this->purgeCompanySessions($target);

        $this->audit('admin.company.blocked', $target, ['reason' => $reason]);

        return back()->with('success', __('larafoundry::admin.companies.blocked'));
    }

    /**
     * Unblock a company. Sessions are not restored — members simply sign back in
     * (or switch back to the company) on their next visit.
     */
    public function unblock(Request $request, string $company): RedirectResponse
    {
        abort_unless($this->policy->block($request->user()), 403);

        $target = $this->find($company);

        $target->forceFill([
            'company_blocked_at' => null,
            'company_blocked_reason' => null,
        ])->save();

        $this->audit('admin.company.unblocked', $target);

        return back()->with('success', __('larafoundry::admin.companies.unblocked'));
    }

    /**
     * Drop the tracked-session bookkeeping that points devices at this company.
     *
     * This is NOT a logout: `user_sessions` is the device-tracking table, not the
     * auth session, so deleting these rows ends no authentication. It only clears
     * `active_company_id` for the rows scoped to this company, keyed by that
     * column (phase 1.2) so it hits exactly the devices inside the blocked company
     * right now — a member's rows in their other companies are untouched. The
     * actual lock-out is EnsureActiveTenant on the next request; this just stops a
     * stale tracked row from keeping the blocked company "active".
     */
    protected function purgeCompanySessions(Model $company): void
    {
        UserSession::query()
            ->where('active_company_id', $company->getKey())
            ->delete();
    }

    /**
     * Write one super-admin action to the activity log.
     *
     * Mirrors UserController::audit() — the `admin` log name, a `target_id`
     * property and the affected company as subject — so console actions record
     * consistently. Geo is enqueued (`geoSync: false`) so a slow provider never
     * blocks the operator.
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
     * @return Builder<Model>
     */
    protected function query()
    {
        return $this->model()::query();
    }

    /**
     * Resolve a company by its uuid route key (Company::getRouteKeyName).
     */
    protected function find(string $company): Model
    {
        return $this->query()->where('uuid', $company)->firstOrFail();
    }

    /**
     * @return class-string<Model>
     */
    protected function model(): string
    {
        /** @var class-string<Model> $model */
        $model = config('larafoundry.tenancy.company_model');

        return $model;
    }

    protected function perPage(): int
    {
        return (int) config('larafoundry.admin.companies_per_page', 21);
    }
}
