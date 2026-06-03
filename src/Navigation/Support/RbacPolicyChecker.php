<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Navigation\Support;

use Dmitryisaenko\LaraFoundry\Navigation\Contracts\PolicyChecker;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Bridges menu visibility to the RBAC trait (phase 1.3 over 2.3).
 *
 * A menu item's `policy` is a permission slug; this checker answers it through
 * `HasRolesAndPermissions::hasPermissionTo($slug, $activeCompany)` — so the same
 * super-admin → owner → roles ∪ grants − revokes rule that guards the routes
 * also decides what appears in the menu. The active company is read from the
 * tenancy trait so the check is scoped to the user's current context.
 *
 * A user model without the RBAC trait (mis-wired host) fails closed: items with
 * a policy are hidden rather than shown.
 */
class RbacPolicyChecker implements PolicyChecker
{
    public function check(Authenticatable $user, string $policy): bool
    {
        if (! method_exists($user, 'hasPermissionTo')) {
            return false;
        }

        return $user->hasPermissionTo($policy, $this->activeCompany($user));
    }

    /**
     * The user's active company, when the tenancy trait is present. Null means
     * "global scope only" — `hasPermissionTo` handles that.
     */
    protected function activeCompany(Authenticatable $user): mixed
    {
        if (! method_exists($user, 'getActiveCompany')) {
            return null;
        }

        return $user->getActiveCompany();
    }
}
