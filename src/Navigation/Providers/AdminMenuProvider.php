<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Navigation\Providers;

use Dmitryisaenko\LaraFoundry\Navigation\Contracts\MenuProviderInterface;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuItem;
use Dmitryisaenko\LaraFoundry\Notifications\Http\Controllers\Admin\BroadcastNotificationController;

/**
 * Core menu for the super-admin operator console.
 *
 * Populates the 'admin' level with the platform surfaces the core ships:
 * the Dashboard (phase 3.4), Users (phase 2.3), Companies (phase 3.3), the
 * Activity Log (phase 2.1), Broadcasts (phase 4.1), Support tickets
 * (phase 4.2), the Email templates editor (phase 5.1), the Legal pages editor
 * (phase 5.3), the Help editor (v0.48.0). Payments, Promo codes, Affiliates and Payouts are added by the
 * billing module's own menu providers, only while billing is enabled. The
 * app-scope Settings screen is intentionally NOT surfaced — its two keys
 * (support_email / signups_enabled) are reserved config with no consumer yet.
 * The whole zone already sits behind the
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

        return array_values(array_filter([
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
            // Off with `larafoundry-notifications.broadcasts.enabled` (v0.44.0) - the
            // routes stay registered and answer 404, so Route::has() alone would keep it.
            BroadcastNotificationController::enabled() ? new MenuItem(
                labelKey: 'Broadcasts',
                route: 'admin.notifications.index',
                icon: 'broadcast',
                order: 25,
                activePatterns: ['admin.notifications.*'],
            ) : null,
            new MenuItem(
                labelKey: 'Support',
                route: 'admin.tickets.index',
                icon: 'support',
                order: 30,
                activePatterns: ['admin.tickets.*'],
            ),
            new MenuItem(
                labelKey: 'Email templates',
                route: 'admin.email-templates.index',
                icon: 'mail',
                order: 32,
                activePatterns: ['admin.email-templates.*'],
            ),
            new MenuItem(
                labelKey: 'Legal pages',
                route: 'admin.legal-pages.index',
                icon: 'legal',
                order: 33,
                activePatterns: ['admin.legal-pages.*'],
            ),
            // Off with `larafoundry-help.admin.enabled` (v0.48.0) - the routes are
            // then not registered at all, so the item would point nowhere.
            config('larafoundry-help.admin.enabled', true) ? new MenuItem(
                labelKey: 'Help',
                route: 'admin.help-articles.index',
                icon: 'help',
                order: 34,
                activePatterns: ['admin.help-articles.*'],
            ) : null,
        ]));
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
