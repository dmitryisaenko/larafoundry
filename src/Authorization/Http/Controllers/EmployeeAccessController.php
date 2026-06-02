<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Authorization\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Authorization\Models\Permission;
use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Authorization\Support\PermissionCatalog;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Concerns\ResolvesActiveCompany;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Assign roles and individual permission overrides to a member of the active
 * company (phase 1.3).
 *
 * The target user is resolved THROUGH the active company's members, so you can
 * only ever touch someone who is actually in your company (anti-IDOR). Owners are
 * untouchable here: their access comes from the `is_owner` flag bypass, not roles,
 * and stripping/overriding it via this path must not be possible. Roles offered
 * for assignment are constrained to the company's own roles; individual
 * permissions to the known catalog.
 */
class EmployeeAccessController extends Controller
{
    use AuthorizesRequests;
    use ResolvesActiveCompany;

    public function __construct(protected PermissionCatalog $catalog) {}

    /**
     * Replace a member's roles within the active company.
     */
    public function syncRoles(Request $request, int $user): RedirectResponse
    {
        $this->authorize('company.employees.assign_role');

        $company = $this->activeCompany($request);
        $member = $this->companyMember($company, $user);

        $validated = $request->validate([
            'roles' => ['array'],
            'roles.*' => [
                'integer',
                Rule::exists('roles', 'id')->where('company_id', $company->getKey()),
            ],
        ]);

        $roles = Role::where('company_id', $company->getKey())
            ->whereIn('id', $validated['roles'] ?? [])
            ->with('permissions:id,slug')
            ->get();

        // No granting beyond your own authority: the actor may only assign a role
        // whose every permission they themselves hold. Owners/super-admins hold
        // the whole catalog (the bypass), so they are unaffected; a delegated
        // member with `assign_role` cannot smuggle in a role carrying powers they
        // were never given (e.g. company.roles.delete).
        $held = $this->heldPermissions($request, $company);

        foreach ($roles as $role) {
            abort_if(
                $role->permissions->pluck('slug')->diff($held)->isNotEmpty(),
                403,
            );
        }

        $member->syncRoles($roles->all(), $company, $request->user()->getAuthIdentifier());

        return back()->with('status', __('larafoundry::authorization.roles_assigned'));
    }

    /**
     * Grant and/or revoke individual permissions for a member (overrides roles).
     */
    public function updatePermissions(Request $request, int $user): RedirectResponse
    {
        $this->authorize('company.employees.grant_permissions');

        $company = $this->activeCompany($request);
        $member = $this->companyMember($company, $user);

        $slugRule = ['string', Rule::in($this->catalog->slugs())];
        $validated = $request->validate([
            'grant' => ['array'],
            'grant.*' => $slugRule,
            'revoke' => ['array'],
            'revoke.*' => $slugRule,
        ]);

        $actorId = $request->user()->getAuthIdentifier();

        // No granting beyond your own authority (see syncRoles): a delegated member
        // can only grant permissions they themselves hold, so `grant_permissions`
        // cannot be parlayed into self-escalation to role-management/owner powers.
        // Revoking is a downgrade and needs no such check.
        $held = $this->heldPermissions($request, $company);

        foreach ($validated['grant'] ?? [] as $slug) {
            abort_unless($held->contains($slug), 403);
        }

        foreach ($this->resolvePermissions($validated['grant'] ?? []) as $permission) {
            $member->givePermissionTo($permission, $company, $actorId);
        }

        foreach ($this->resolvePermissions($validated['revoke'] ?? []) as $permission) {
            $member->revokePermissionFrom($permission, $company, $actorId);
        }

        return back()->with('status', __('larafoundry::authorization.permissions_updated'));
    }

    /**
     * A member of the active company (excluding owners — see class docblock).
     */
    protected function companyMember(Company $company, int $userId)
    {
        $member = $company->users()->find($userId);

        abort_if($member === null, 404);
        abort_if((bool) $member->pivot->is_owner, 403);

        return $member;
    }

    /**
     * @param  list<string>  $slugs
     * @return Collection<int, Permission>
     */
    protected function resolvePermissions(array $slugs)
    {
        return Permission::whereIn('slug', $slugs)->get();
    }

    /**
     * The acting user's own effective permission slugs in the active company.
     *
     * Owners and super-admins hold the entire catalog (via the bypass inside
     * getAllPermissions), so they can assign/grant anything; a delegated member
     * is bounded to exactly what they themselves were given.
     *
     * @return Collection<int, string>
     */
    protected function heldPermissions(Request $request, Company $company): Collection
    {
        return $request->user()->getAllPermissions($company);
    }
}
