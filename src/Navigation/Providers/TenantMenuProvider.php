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
 * The core contributes the cross-cutting tenant screens it actually ships
 * (phases 1.2/1.3/5.1): Employees, Roles and Company settings. They are grouped
 * under one collapsible "My company" parent so the sidebar reads as a single
 * company section rather than three loose links — matching the legacy two-row
 * layout where "My company" was its own group. Each child carries its RBAC
 * permission slug, so the {@see RbacPolicyChecker} hides it from members who lack
 * the right (owners/super-admins see everything); when none survive, the whole
 * group is dropped by the builder (unreachable empty group).
 *
 * The "My company" parent is a PURE group (no own route/url). The host may grow
 * the same group from its own provider — e.g. it adds the Dashboard child — and
 * the {@see MenuBuilder} merges top-level pure groups that share a labelKey into
 * one, so core and host children land under the same parent.
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
                labelKey: 'My company',
                icon: 'companies',
                order: 90,
                submenu: [
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
                    // Company settings — guarded by the RBAC permission (owners and
                    // super-admins bypass), scoped to the active company (phase 5.1).
                    // Carries a policy so the "bare member sees an empty menu"
                    // invariant holds (a member without company.settings.view is
                    // filtered out). Personal account settings are NOT a company
                    // item — they are reached from the host's user menu.
                    new MenuItem(
                        labelKey: 'Company settings',
                        route: 'settings.company',
                        policy: 'company.settings.view',
                        icon: 'settings',
                        order: 95,
                        activePatterns: ['settings.company'],
                    ),
                ],
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
