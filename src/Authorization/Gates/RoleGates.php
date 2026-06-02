<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Authorization\Gates;

use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Role-management abilities for the company UI (phase 1.3).
 *
 * Separate from the per-slug permission gates because managing a role is more than
 * holding a permission — it also depends on the role itself: you may only touch a
 * role that belongs to YOUR active company, you cannot edit a global/template row,
 * and you cannot delete a role that members still hold. These structural rules
 * live here; the underlying authority comes from the `company.roles.*` permission
 * (owners pass it via the owner bypass, delegated members via an explicit grant).
 *
 * Ability names mirror the donor (`roles.viewAny`, `roles.update`, …) so the
 * management controller authorizes against a model where it has one.
 */
class RoleGates
{
    public static function register(): void
    {
        Gate::define('roles.viewAny', fn (Authenticatable $user) => static::can($user, 'company.roles.view'));

        Gate::define('roles.create', fn (Authenticatable $user) => static::can($user, 'company.roles.create'));

        Gate::define('roles.update', function (Authenticatable $user, Role $role) {
            return static::inActiveCompany($user, $role)
                && $role->isEditable()
                && static::can($user, 'company.roles.update');
        });

        Gate::define('roles.delete', function (Authenticatable $user, Role $role) {
            return static::inActiveCompany($user, $role)
                && $role->canBeDeleted()
                && static::can($user, 'company.roles.delete');
        });

        Gate::define('roles.managePermissions', function (Authenticatable $user, Role $role) {
            return static::inActiveCompany($user, $role)
                && $role->isEditable()
                && static::can($user, 'company.roles.update');
        });
    }

    /**
     * Whether the user holds a permission in their active company context.
     */
    protected static function can(Authenticatable $user, string $permission): bool
    {
        if (! method_exists($user, 'hasPermissionTo')) {
            return false;
        }

        $company = method_exists($user, 'getActiveCompany') ? $user->getActiveCompany() : null;

        if ($company === null) {
            return false;
        }

        return $user->hasPermissionTo($permission, $company);
    }

    /**
     * Whether the role belongs to the user's currently active company — the IDOR
     * guard that stops one company's owner editing another company's roles.
     */
    protected static function inActiveCompany(Authenticatable $user, Role $role): bool
    {
        $company = method_exists($user, 'getActiveCompany') ? $user->getActiveCompany() : null;

        return $company !== null
            && $role->company_id !== null
            && (int) $role->company_id === (int) $company->getKey();
    }
}
