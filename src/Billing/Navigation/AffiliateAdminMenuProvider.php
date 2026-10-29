<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Navigation;

use Dmitryisaenko\LaraFoundry\Navigation\Contracts\MenuProviderInterface;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuBuilder;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuItem;

/**
 * Adds the affiliate console surfaces to the core super-admin menu (Ф4.4): Affiliates and
 * Payouts. Registered onto the core {@see MenuBuilder} in the provider's boot — the same
 * additive idiom a host uses — only when the affiliate program is enabled, so the items
 * never appear without their screens.
 *
 * Priority 10 (after the core provider's 0), ordered after the payments/promo items. The
 * items carry NO permission slug: the whole zone is behind `larafoundry.admin`. Icons reuse
 * NavIcon glyphs (`affiliate` for partners, `activity` for the payout ledger); labels are i18n keys translated in Vue.
 */
final class AffiliateAdminMenuProvider implements MenuProviderInterface
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
                labelKey: 'Affiliates',
                route: 'admin.affiliates.index',
                icon: 'affiliate',
                order: 40,
                activePatterns: ['admin.affiliates.*'],
            ),
            new MenuItem(
                labelKey: 'Payouts',
                route: 'admin.payouts.index',
                icon: 'activity',
                order: 45,
                activePatterns: ['admin.payouts.*'],
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
