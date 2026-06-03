<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Navigation\Providers;

use Dmitryisaenko\LaraFoundry\Navigation\Contracts\MenuProviderInterface;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuItem;
use Dmitryisaenko\LaraFoundry\Navigation\Support\RbacPolicyChecker;

/**
 * Core menu for the tenant zone — an owner/employee inside a company
 * (phase 2.3).
 *
 * The core contributes only the tenant screens it actually ships (phases
 * 1.2/1.3): Employees and Roles. Each carries its RBAC permission slug, so the
 * {@see RbacPolicyChecker} hides
 * it from members who lack the right (owners/super-admins see everything).
 *
 * The host owns the rest of the tenant menu (its domain modules) and adds it
 * through its own {@see MenuProviderInterface} — the core does not ship a
 * menu-builder UI (a small SaaS does not need DB-driven menus; see core-scope).
 * A low priority (100) keeps host items free to slot above or below by `order`.
 */
class TenantMenuProvider implements MenuProviderInterface
{
    public function getMenuItems(string $level): array
    {
        if (! $this->supports($level)) {
            return [];
        }

        return [
            new MenuItem(
                labelKey: 'Employees',
                route: 'tenancy.employees.index',
                policy: 'company.employees.view',
                icon: 'employees',
                order: 80,
                activePatterns: ['tenancy.employees.*'],
            ),
            new MenuItem(
                labelKey: 'Roles',
                route: 'authorization.roles.index',
                policy: 'company.roles.view',
                icon: 'roles',
                order: 90,
                activePatterns: ['authorization.roles.*'],
            ),
        ];
    }

    public function supports(string $level): bool
    {
        return $level === 'tenant';
    }

    public function priority(): int
    {
        return 100;
    }
}
