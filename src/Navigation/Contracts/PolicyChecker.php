<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Navigation\Contracts;

use Dmitryisaenko\LaraFoundry\Navigation\Support\RbacPolicyChecker;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Decides whether a user may see a menu item guarded by a permission slug
 * (phase 2.3).
 *
 * The {@see MenuBuilder} depends on this contract, not on the RBAC trait
 * directly, so the filtering rule is swappable. The core binds it to
 * {@see RbacPolicyChecker},
 * which bridges to `HasRolesAndPermissions::hasPermissionTo` (phase 1.3).
 */
interface PolicyChecker
{
    /**
     * Whether the user satisfies the given permission slug in their current
     * (active-company) context.
     */
    public function check(Authenticatable $user, string $policy): bool;
}
