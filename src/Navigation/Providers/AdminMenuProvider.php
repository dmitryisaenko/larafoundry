<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Navigation\Providers;

use Dmitryisaenko\LaraFoundry\Navigation\Contracts\MenuProviderInterface;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuItem;

/**
 * Core menu for the super-admin operator console.
 *
 * Populates the 'admin' level with the platform surfaces the core ships:
 * the Dashboard (phase 3.4), Users (phase 2.3), Companies (phase 3.3), the
 * Activity Log (phase 2.1) and Broadcasts (phase 4.1). The whole zone already
 * sits behind the
 * `larafoundry.admin` gate (super-admin via VisitorStatus), so these items carry
 * NO permission slug — the zone gate is the authority.
 *
 * Labels are i18n keys (decision D-nav-c), translated in Vue.
 */
class AdminMenuProvider implements MenuProviderInterface
{
    public function getMenuItems(string $level): array
    {
        if (! $this->supports($level)) {
            return [];
        }

        return [
            new MenuItem(
                labelKey: 'Dashboard',
                route: 'admin.dashboard.index',
                icon: 'dashboard',
                order: 5,
                activePatterns: ['admin.dashboard.*'],
            ),
            new MenuItem(
                labelKey: 'Users',
                route: 'admin.users.index',
                icon: 'users',
                order: 10,
                activePatterns: ['admin.users.*'],
            ),
            new MenuItem(
                labelKey: 'Companies',
                route: 'admin.companies.index',
                icon: 'companies',
                order: 15,
                activePatterns: ['admin.companies.*'],
            ),
            new MenuItem(
                labelKey: 'Activity log',
                route: 'admin.activity-log.index',
                icon: 'activity',
                order: 20,
                activePatterns: ['admin.activity-log.*'],
            ),
            new MenuItem(
                labelKey: 'Broadcasts',
                route: 'admin.notifications.index',
                icon: 'broadcast',
                order: 25,
                activePatterns: ['admin.notifications.*'],
            ),
        ];
    }

    public function supports(string $level): bool
    {
        return $level === 'admin';
    }

    public function priority(): int
    {
        return 0;
    }
}
