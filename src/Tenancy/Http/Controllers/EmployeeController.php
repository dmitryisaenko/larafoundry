<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\CreateEmployeeAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\InviteEmployeesAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\RejectRemovalRequestAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\RemoveEmployeeAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\UpdateEmployeeAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRemovalCancelled;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRemovalRequested;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\InvitationResent;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\InvitationWithdrawn;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Concerns\ResolvesActiveCompany;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Requests\CreateEmployeeRequest;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Requests\InviteEmployeeRequest;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Requests\UpdateEmployeeRequest;
use Dmitryisaenko\LaraFoundry\Tenancy\Jobs\SendCompanyInvitationJob;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\CompanyInvitation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manage members and pending invitations of the active company (teams mode).
 *
 * Every action is scoped to the caller's ACTIVE company, re-resolved from the
 * user — never an id from the request — so a user can only ever manage the
 * company they currently act as. Owner-only operations assert ownership; a
 * member may only request their own removal. Role assignment is absent (RBAC,
 * phase 1.3).
 *
 * Invitations are additionally checked to belong to the active company before
 * resend/delete (anti-IDOR, donor guard kept).
 */
class EmployeeController extends Controller
{
    use ResolvesActiveCompany;

    public function index(Request $request): Response
    {
        $company = $this->activeCompany($request);

        return Inertia::render('Tenancy/Employees', [
            // Owner(s) first, then the rest in join order; each row carries the
            // display identity (name + lastname + avatar), the roles held in THIS
            // company, and the join date. The sort is stable, so non-owners keep
            // their query order.
            'employees' => $company->users()->get()->map(function ($user) use ($company) {
                $roles = method_exists($user, 'getRolesInCompany')
                    ? $user->getRolesInCompany($company)
                    : collect();

                return [
                    'id' => $user->getKey(),
                    'name' => $user->name,
                    'lastname' => $user->lastname,
                    'avatar_url' => $user->avatar_url,
                    'email' => $user->email,
                    'is_owner' => (bool) $user->pivot->is_owner,
                    'roles' => $roles->pluck('name')->values(),
                    'role_ids' => $roles->pluck('id')->values(),
                    'joined_at' => $user->pivot->created_at,
                    'removal_requested' => $user->pivot->removal_requested_at !== null,
                ];
            })->sortByDesc('is_owner')->values(),
            'invitations' => $company->invitations()->pending()->get()->map(fn ($invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'expires_at' => $invitation->expires_at,
            ]),
            // The company's assignable roles for the optional role-on-invite select
            // (company-scoped only — never global/template roles).
            'roles' => Role::query()
                ->where('company_id', $company->getKey())
                ->orderBy('name')
                ->get(['id', 'name']),
            'is_owner' => $request->user()->isOwnerOfActiveCompany(),
        ]);
    }

    public function invite(InviteEmployeeRequest $request, InviteEmployeesAction $action): RedirectResponse
    {
        $company = $this->ownedActiveCompany($request);

        $action->execute(
            $company,
            [['email' => $request->validated('email'), 'role_id' => $request->validated('role_id')]],
            $request->user()->getAuthIdentifier(),
        );

        return back()->with('status', __('larafoundry::tenancy.invitation_sent'));
    }

    /**
     * Create a member account directly (owner-only), bypassing the email invite.
     * The company is the caller's ACTIVE, OWNED company — never an id from input.
     */
    public function store(CreateEmployeeRequest $request, CreateEmployeeAction $action): RedirectResponse
    {
        $company = $this->ownedActiveCompany($request);

        $action->execute(
            $company,
            $request->validated(),
            $request->user()->getAuthIdentifier(),
        );

        return back()->with('status', __('larafoundry::tenancy.employee_created'));
    }

    /**
     * Update a member's identity (name, lastname, avatar) and roles (owner-only).
     * The target is resolved THROUGH the owner's active company (anti-IDOR); the
     * owner themselves is not editable here (they manage their own profile).
     */
    public function update(UpdateEmployeeRequest $request, int $user, UpdateEmployeeAction $action): RedirectResponse
    {
        $company = $this->ownedActiveCompany($request);

        $employee = $company->users()->find($user);
        abort_if($employee === null, 404);
        abort_if((bool) $employee->pivot->is_owner, 403);

        $action->execute($company, $employee, $request->validated(), $request->user()->getAuthIdentifier());

        return back()->with('status', __('larafoundry::tenancy.employee_updated'));
    }

    public function resendInvitation(Request $request, int $invitation): RedirectResponse
    {
        $company = $this->ownedActiveCompany($request);

        $companyInvitation = $this->companyInvitation($company, $invitation);

        SendCompanyInvitationJob::dispatch($companyInvitation);

        // Only audit a resend of a still-actionable (pending, unexpired) invite —
        // a consumed/expired invitation re-sent by a stale request is not a
        // meaningful "resent" event.
        if ($companyInvitation->isActionable()) {
            InvitationResent::dispatch($companyInvitation);
        }

        return back()->with('status', __('larafoundry::tenancy.invitation_sent'));
    }

    public function deleteInvitation(Request $request, int $invitation): RedirectResponse
    {
        $company = $this->ownedActiveCompany($request);

        $companyInvitation = $this->companyInvitation($company, $invitation);

        // Dispatch BEFORE delete so the company + invited address are still readable.
        InvitationWithdrawn::dispatch($companyInvitation);

        $companyInvitation->delete();

        return back()->with('status', __('larafoundry::tenancy.invitation_revoked'));
    }

    public function removeEmployee(Request $request, RemoveEmployeeAction $action): RedirectResponse
    {
        $company = $this->ownedActiveCompany($request);

        $userId = $request->integer('user_id');
        $employee = $company->users()->find($userId);

        if ($employee === null) {
            abort(404);
        }

        // An owner cannot be removed via this path (would orphan the company).
        if ((bool) $employee->pivot->is_owner) {
            abort(403);
        }

        $action->execute($company, $employee);

        return back()->with('status', __('larafoundry::tenancy.employee_removed'));
    }

    /**
     * An owner rejects a member's pending removal request (matrix row 4.2): the
     * member stays. Owner-only, and the target is resolved THROUGH the owner's
     * active company (anti-IDOR) so a user outside the company is never touched;
     * the owner's own row cannot be a removal target (they cannot request removal).
     */
    public function rejectRemoval(Request $request, RejectRemovalRequestAction $action): RedirectResponse
    {
        $company = $this->ownedActiveCompany($request);

        $userId = $request->integer('user_id');
        $employee = $company->users()->find($userId);

        abort_if($employee === null, 404);
        abort_if((bool) $employee->pivot->is_owner, 403);

        // Nothing to reject when there is no pending request — surface a 404 rather
        // than silently redirecting, so a stale action gets a clear signal.
        abort_if($employee->pivot->removal_requested_at === null, 404);

        $action->execute($company, $employee);

        return back()->with('status', __('larafoundry::tenancy.removal_rejected'));
    }

    /**
     * A member asks to be removed from the active company (allowed without
     * ownership; reachable even with no other active company per config).
     *
     * Owners cannot request their own removal — that would orphan the tenant
     * (a company with no owner). Donor guard kept.
     */
    public function requestRemoval(Request $request): RedirectResponse
    {
        $user = $request->user();
        $company = $user?->getActiveCompany();

        // These routes are exempt from EnsureActiveTenant so a member whose only
        // company was removed can still reach them — but with no active company
        // there is nothing to leave, so fail gracefully instead of a 403.
        if ($company === null) {
            return back()->with('error', __('larafoundry::tenancy.no_active_company'));
        }

        if ($user->isOwnerOf($company)) {
            abort(403, __('larafoundry::tenancy.owner_cannot_leave'));
        }

        // Idempotent (mirrors the archive guard): re-requesting an already-pending
        // removal is a no-op — no re-stamp and no duplicate audit event.
        if (! $this->hasPendingRemoval($company, $user)) {
            $company->users()->updateExistingPivot($user->getAuthIdentifier(), [
                'removal_requested_at' => now(),
                'removal_requested_by' => $user->getAuthIdentifier(),
            ]);

            EmployeeRemovalRequested::dispatch($company, $user);
        }

        return back()->with('status', __('larafoundry::tenancy.removal_requested'));
    }

    public function cancelRemoval(Request $request): RedirectResponse
    {
        $user = $request->user();
        $company = $user?->getActiveCompany();

        if ($company === null) {
            return back()->with('error', __('larafoundry::tenancy.no_active_company'));
        }

        // Idempotent: cancelling when there is no pending request is a no-op — no
        // phantom "removal cancelled" audit event.
        if ($this->hasPendingRemoval($company, $user)) {
            $company->users()->updateExistingPivot($user->getAuthIdentifier(), [
                'removal_requested_at' => null,
                'removal_requested_by' => null,
            ]);

            EmployeeRemovalCancelled::dispatch($company, $user);
        }

        return back()->with('status', __('larafoundry::tenancy.removal_cancelled'));
    }

    /**
     * Whether the user currently has a pending removal request in this company.
     * Read from the membership pivot so request/cancel can be idempotent and only
     * emit an audit event on a real state transition.
     */
    protected function hasPendingRemoval(Company $company, Authenticatable $user): bool
    {
        $membership = $company->users()->find($user->getAuthIdentifier());

        return $membership !== null && $membership->pivot->removal_requested_at !== null;
    }

    /**
     * Resolve a pending invitation THROUGH the company's relation (anti-IDOR).
     *
     * Loading via $company->invitations() instead of a global id means an
     * invitation belonging to another company simply isn't found (404) — the
     * isolation is structural, not a guard a future action could forget. This is
     * why these routes bind the raw {invitation} id rather than the model.
     */
    protected function companyInvitation(Company $company, int $invitationId): CompanyInvitation
    {
        /** @var CompanyInvitation */
        return $company->invitations()->findOrFail($invitationId);
    }
}
