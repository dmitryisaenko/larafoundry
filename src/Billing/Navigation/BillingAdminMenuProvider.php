<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Navigation;

use Dmitryisaenko\LaraFoundry\Navigation\Contracts\MenuProviderInterface;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuBuilder;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuItem;

/**
 * Adds the add-on's two operator-console surfaces to the core super-admin menu
 * (sub-phase B7b): Payments and Promo codes.
 *
 * Registered onto the core {@see MenuBuilder}
 * in the provider's boot — the same additive idiom a host uses to grow the menu without
 * editing the core. Priority 10 runs after the core's own provider (0), so these items
 * merge after Users/Companies/Activity; the final order is still each item's `order`.
 *
 * The items carry NO permission slug: the whole zone is behind the `larafoundry.admin`
 * gate, so the zone is the authority (matching the core AdminMenuProvider). Icons are
 * the NavIcon glyphs drawn for these screens (`billing`, `promo`). Labels are i18n keys,
 * translated in Vue (decision D-nav-c).
 */
final class BillingAdminMenuProvider implements MenuProviderInterface
{
    /**
     * @return array<int, MenuItem>
     */
    public function getMenuItems(string $level): array
    {
        if (! $this->supports($level)) {
            return [];
        }

        return [
            new MenuItem(
                labelKey: 'Payments',
                route: 'admin.payments.index',
                icon: 'billing',
                order: 30,
                activePatterns: ['admin.payments.*'],
            ),
            new MenuItem(
                labelKey: 'Promo codes',
                route: 'admin.promo-codes.index',
                icon: 'promo',
                order: 35,
                activePatterns: ['admin.promo-codes.*'],
            ),
        ];
    }

    public function supports(string $level): bool
    {
        return $level === 'admin';
    }

    public function priority(): int
    {
        return 10;
    }
}
