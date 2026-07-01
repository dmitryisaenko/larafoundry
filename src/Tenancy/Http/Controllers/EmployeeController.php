<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\CreateEmployeeAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\InviteEmployeesAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\RemoveEmployeeAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\UpdateEmployeeAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Concerns\ResolvesActiveCompany;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Requests\CreateEmployeeRequest;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Requests\InviteEmployeeRequest;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Requests\UpdateEmployeeRequest;
use Dmitryisaenko\LaraFoundry\Tenancy\Jobs\SendCompanyInvitationJob;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\CompanyInvitation;
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

        SendCompanyInvitationJob::dispatch($this->companyInvitation($company, $invitation));

        return back()->with('status', __('larafoundry::tenancy.invitation_sent'));
    }

    public function deleteInvitation(Request $request, int $invitation): RedirectResponse
    {
        $company = $this->ownedActiveCompany($request);

        $this->companyInvitation($company, $invitation)->delete();

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

        $company->users()->updateExistingPivot($user->getAuthIdentifier(), [
            'removal_requested_at' => now(),
            'removal_requested_by' => $user->getAuthIdentifier(),
        ]);

        return back()->with('status', __('larafoundry::tenancy.removal_requested'));
    }

    public function cancelRemoval(Request $request): RedirectResponse
    {
        $user = $request->user();
        $company = $user?->getActiveCompany();

        if ($company === null) {
            return back()->with('error', __('larafoundry::tenancy.no_active_company'));
        }

        $company->users()->updateExistingPivot($user->getAuthIdentifier(), [
            'removal_requested_at' => null,
            'removal_requested_by' => null,
        ]);

        return back()->with('status', __('larafoundry::tenancy.removal_cancelled'));
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
