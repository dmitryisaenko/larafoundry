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
 * Activity Log (phase 2.1), Broadcasts (phase 4.1), Support tickets
 * (phase 4.2), the Email templates editor (phase 5.1), the Legal pages editor
 * (phase 5.3) and the monetization stubs Payments, Affiliates and Promo codes
 * (phase 4 — inert upsell slots reserved for the paid billing add-on). The
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
            new MenuItem(
                labelKey: 'Payments',
                route: 'admin.payments.index',
                icon: 'billing',
                order: 34,
                activePatterns: ['admin.payments.*'],
            ),
            new MenuItem(
                labelKey: 'Affiliates',
                route: 'admin.affiliates.index',
                icon: 'affiliate',
                order: 36,
                activePatterns: ['admin.affiliates.*'],
            ),
            new MenuItem(
                labelKey: 'Promo codes',
                route: 'admin.promo.index',
                icon: 'promo',
                order: 37,
                activePatterns: ['admin.promo.*'],
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
