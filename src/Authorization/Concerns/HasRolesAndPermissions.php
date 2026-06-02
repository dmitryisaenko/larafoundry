<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Authorization\Concerns;

use Dmitryisaenko\LaraFoundry\Auth\Concerns\IsLaraFoundryUser;
use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Dmitryisaenko\LaraFoundry\Authorization\Models\Permission;
use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Tenancy\Concerns\BelongsToTenancy;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RBAC behaviour for a user model (phase 1.3).
 *
 * Composed alongside {@see IsLaraFoundryUser}
 * and {@see BelongsToTenancy} per the
 * trait-slot idiom (decision D4): identity in the auth trait, tenancy in its trait,
 * authorization here. The host does `use IsLaraFoundryUser, BelongsToTenancy,
 * HasRolesAndPermissions;`.
 *
 * Ownership is NOT redefined here: `isOwnerOf()` / `getActiveCompany()` come from
 * BelongsToTenancy (decision D1.3-b — owner is the `is_owner` pivot flag, and an
 * owner short-circuits every permission check). super-admin is the core's
 * VisitorStatus identity flag (decision D1.3-e), never a role.
 *
 * Check priority in {@see hasPermissionTo}: super-admin → owner → the resolved
 * permission set, which is `(company roles ∪ global roles ∪ individual grants) −
 * individual revocations`. Because the (user, permission, company) pivot is unique,
 * a permission is either granted or revoked (never both), so the set arithmetic
 * reproduces the legacy step-by-step revoke-beats-grant-beats-role order exactly —
 * while resolving the set ONCE per company per request (memoized) instead of
 * firing several `exists()` queries on every single check (decision D1.3-g).
 *
 * @mixin Model
 */
trait HasRolesAndPermissions
{
    /**
     * Resolved permission slug sets, memoized per company for the request.
     *
     * Key is the company id, or '__global' for the null-company (global-only)
     * scope. Reset whenever an assignment mutates so a check after a change sees
     * fresh data.
     *
     * @var array<int|string, Collection<int, string>>
     */
    protected array $resolvedPermissions = [];

    /**
     * Ownership decisions memoized per company for the request, so repeated
     * permission checks don't re-run the owner `exists()` query every time.
     *
     * @var array<int|string, bool>
     */
    protected array $resolvedOwnership = [];

    /**
     * Roles assigned to this user (tenant-scoped via the pivot's company_id).
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot('company_id', 'assigned_by_id')
            ->withTimestamps();
    }

    /**
     * Individual permission grants/revocations for this user.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permissions')
            ->withPivot('company_id', 'is_revoked', 'granted_by_id')
            ->withTimestamps();
    }

    /**
     * Whether this user bypasses all permission checks as a super-admin.
     *
     * Sourced from the core VisitorStatus (a flipped `is_admin` flag alone is not
     * enough when an admin allow-list email is configured), never a role.
     */
    public function isSuperAdmin(): bool
    {
        return app(VisitorStatus::class)->isAdmin($this);
    }

    /**
     * Whether this user holds ONLY the global `authenticated` role (freshly
     * registered, no company roles yet).
     */
    public function hasOnlyAuthenticatedRole(): bool
    {
        $allRoles = $this->roles()->get();

        if ($allRoles->count() !== 1) {
            return false;
        }

        $role = $allRoles->first();

        return $role->slug === 'authenticated' && $role->is_global === true;
    }

    /**
     * Whether the user has a permission, in the given company context.
     *
     * Passing null checks the global scope only (the gates supply the active
     * company — see PermissionGateRegistrar). Owners get everything in their
     * company; super-admins get everything everywhere.
     */
    public function hasPermissionTo(Permission|string $permission, Company|int|null $company = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($company !== null && $this->userOwns($company)) {
            return true;
        }

        $slug = $permission instanceof Permission ? $permission->slug : $permission;

        return $this->getAllPermissions($company)->contains($slug);
    }

    /**
     * @param  array<int, Permission|string>  $permissions
     */
    public function hasAnyPermission(array $permissions, Company|int|null $company = null): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermissionTo($permission, $company)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, Permission|string>  $permissions
     */
    public function hasAllPermissions(array $permissions, Company|int|null $company = null): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->hasPermissionTo($permission, $company)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether this user's permission slug set matches a wildcard pattern
     * (`orders.*`). Owners/super-admins match any pattern.
     */
    public function hasPermissionPattern(string $pattern, Company|int|null $company = null): bool
    {
        if ($this->isSuperAdmin() || ($company !== null && $this->userOwns($company))) {
            return true;
        }

        $regex = '/^'.str_replace('\\*', '.*', preg_quote($pattern, '/')).'$/';

        foreach ($this->getAllPermissions($company) as $slug) {
            if (preg_match($regex, $slug)) {
                return true;
            }
        }

        return false;
    }

    public function hasRole(Role|string $role, Company|int|null $company = null): bool
    {
        $roleSlug = $role instanceof Role ? $role->slug : $role;

        return $this->roles()
            ->where('roles.slug', $roleSlug)
            ->wherePivot('company_id', $this->companyId($company))
            ->exists();
    }

    /**
     * @param  array<int, Role|string>  $roles
     */
    public function hasAnyRole(array $roles, Company|int|null $company = null): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role, $company)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, Role|string>  $roles
     */
    public function hasAllRoles(array $roles, Company|int|null $company = null): bool
    {
        foreach ($roles as $role) {
            if (! $this->hasRole($role, $company)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The user's effective permission slugs in a company: (company roles ∪ global
     * roles ∪ grants) − revocations. Memoized per company for the request.
     *
     * Super-admins and owners hold every catalog permission — returned eagerly so
     * callers that enumerate (e.g. building a UI permission map) see the full set.
     *
     * @return Collection<int, string>
     */
    public function getAllPermissions(Company|int|null $company = null): Collection
    {
        $companyId = $this->companyId($company);
        $key = $companyId ?? '__global';

        if (isset($this->resolvedPermissions[$key])) {
            return $this->resolvedPermissions[$key];
        }

        // Owners/super-admins hold the whole catalog. Memoize it under the same
        // per-company key so the hot path (per-slug gate, shared Inertia prop) does
        // not re-run the full permissions read on every check.
        if ($this->isSuperAdmin() || ($company !== null && $this->userOwns($company))) {
            return $this->resolvedPermissions[$key] = Permission::query()->pluck('slug');
        }

        $fromCompanyRoles = $this->rolePermissionSlugs($companyId);
        $fromGlobalRoles = $companyId === null ? collect() : $this->rolePermissionSlugs(null);

        $granted = $this->permissions()
            ->wherePivot('company_id', $companyId)
            ->wherePivot('is_revoked', false)
            ->pluck('permissions.slug');

        $revoked = $this->permissions()
            ->wherePivot('company_id', $companyId)
            ->wherePivot('is_revoked', true)
            ->pluck('permissions.slug');

        $resolved = $fromCompanyRoles
            ->merge($fromGlobalRoles)
            ->merge($granted)
            ->diff($revoked)
            ->unique()
            ->values();

        return $this->resolvedPermissions[$key] = $resolved;
    }

    /**
     * Roles held in a company (or globally when null).
     *
     * @return Collection<int, Role>
     */
    public function getRolesInCompany(Company|int|null $company = null): Collection
    {
        return $this->roles()
            ->wherePivot('company_id', $this->companyId($company))
            ->get();
    }

    /**
     * @return Collection<int, Role>
     */
    public function getGlobalRoles(): Collection
    {
        return $this->roles()->wherePivot('company_id', null)->get();
    }

    public function assignRole(Role|string $role, Company|int|null $company = null, ?int $assignedById = null): static
    {
        $roleModel = $role instanceof Role ? $role : Role::where('slug', $role)->firstOrFail();
        $companyId = $this->companyId($company);

        $exists = $this->roles()
            ->where('roles.id', $roleModel->id)
            ->wherePivot('company_id', $companyId)
            ->exists();

        if (! $exists) {
            $this->roles()->attach($roleModel->id, [
                'company_id' => $companyId,
                'assigned_by_id' => $assignedById,
            ]);
        }

        return $this->forgetResolvedPermissions();
    }

    public function removeRole(Role|string $role, Company|int|null $company = null): static
    {
        $roleModel = $role instanceof Role ? $role : Role::where('slug', $role)->firstOrFail();

        $this->roles()
            ->wherePivot('company_id', $this->companyId($company))
            ->detach($roleModel->id);

        return $this->forgetResolvedPermissions();
    }

    /**
     * Replace the user's roles in a company with the given set.
     *
     * @param  array<int, Role|string>  $roles
     */
    public function syncRoles(array $roles, Company|int|null $company = null, ?int $assignedById = null): static
    {
        $companyId = $this->companyId($company);

        // Dedupe ids: a repeated role would collide on the unique
        // (user_id, role_id, company_id) index on the second attach.
        $roleIds = collect($roles)
            ->map(fn ($role) => $role instanceof Role ? $role->id : Role::where('slug', $role)->firstOrFail()->id)
            ->unique();

        // Detach + re-attach as one unit so a mid-loop failure can't leave the
        // user with fewer roles than they started with.
        DB::transaction(function () use ($companyId, $roleIds, $assignedById) {
            $this->roles()->wherePivot('company_id', $companyId)->detach();

            foreach ($roleIds as $roleId) {
                $this->roles()->attach($roleId, [
                    'company_id' => $companyId,
                    'assigned_by_id' => $assignedById,
                ]);
            }
        });

        return $this->forgetResolvedPermissions();
    }

    public function givePermissionTo(Permission|string $permission, Company|int|null $company = null, ?int $grantedById = null): static
    {
        return $this->setIndividualPermission($permission, $company, isRevoked: false, actorId: $grantedById);
    }

    public function revokePermissionFrom(Permission|string $permission, Company|int|null $company = null, ?int $grantedById = null): static
    {
        return $this->setIndividualPermission($permission, $company, isRevoked: true, actorId: $grantedById);
    }

    public function removePermission(Permission|string $permission, Company|int|null $company = null): static
    {
        $permissionModel = $this->resolvePermission($permission);

        $this->permissions()
            ->wherePivot('company_id', $this->companyId($company))
            ->detach($permissionModel->id);

        return $this->forgetResolvedPermissions();
    }

    /**
     * Upsert a single grant/revocation row (is_revoked flips an existing one).
     */
    protected function setIndividualPermission(Permission|string $permission, Company|int|null $company, bool $isRevoked, ?int $actorId): static
    {
        $permissionModel = $this->resolvePermission($permission);
        $companyId = $this->companyId($company);

        $existing = $this->permissions()
            ->where('permissions.id', $permissionModel->id)
            ->wherePivot('company_id', $companyId)
            ->exists();

        if ($existing) {
            $this->permissions()->updateExistingPivot($permissionModel->id, [
                'is_revoked' => $isRevoked,
                'granted_by_id' => $actorId,
            ], false);
        } else {
            $this->permissions()->attach($permissionModel->id, [
                'company_id' => $companyId,
                'is_revoked' => $isRevoked,
                'granted_by_id' => $actorId,
            ]);
        }

        return $this->forgetResolvedPermissions();
    }

    /**
     * Permission slugs granted by the user's roles in the given company scope.
     *
     * @return Collection<int, string>
     */
    protected function rolePermissionSlugs(int|string|null $companyId): Collection
    {
        return $this->roles()
            ->wherePivot('company_id', $companyId)
            ->with('permissions:id,slug')
            ->get()
            ->pluck('permissions')
            ->flatten()
            ->pluck('slug')
            ->unique();
    }

    protected function resolvePermission(Permission|string $permission): Permission
    {
        return $permission instanceof Permission
            ? $permission
            : Permission::where('slug', $permission)->firstOrFail();
    }

    /**
     * Owner check delegated to BelongsToTenancy (guarded so the trait still works
     * standalone, e.g. in personal mode with no company concept).
     */
    protected function userOwns(Company|int $company): bool
    {
        if (! method_exists($this, 'isOwnerOf')) {
            return false;
        }

        $key = $this->companyId($company) ?? '__none';

        return $this->resolvedOwnership[$key] ??= $this->isOwnerOf($company);
    }

    protected function companyId(Company|int|null $company): int|string|null
    {
        return $company instanceof Company ? $company->getKey() : $company;
    }

    protected function forgetResolvedPermissions(): static
    {
        $this->resolvedPermissions = [];
        $this->resolvedOwnership = [];

        return $this;
    }
}
