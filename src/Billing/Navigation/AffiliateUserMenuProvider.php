<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Navigation;

use Dmitryisaenko\LaraFoundry\Navigation\Contracts\MenuProviderInterface;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuItem;

/**
 * Adds the partner dashboard link to the regular user ('tenant') menu (Ф4.4).
 *
 * Shown only in `auto` / `self_serve` eligibility — there every user is (or may become) a
 * partner, so the menu item makes sense for all. In `admin` eligibility partners are
 * enrolled individually and reach the dashboard by a direct link, so no global menu item
 * appears. No permission slug: the route's own `auth` guard is the authority.
 */
final class AffiliateUserMenuProvider implements MenuProviderInterface
{
    /**
     * @return array<int, MenuItem>
     */
    public function getMenuItems(string $level): array
    {
        if (! $this->supports($level)) {
            return [];
        }

        $eligibility = (string) config('larafoundry-billing.affiliate.eligibility', 'auto');

        if (! in_array($eligibility, ['auto', 'self_serve'], true)) {
            return [];
        }

        return [
            new MenuItem(
                labelKey: 'Affiliate program',
                route: 'affiliate.dashboard',
                icon: 'activity',
                order: 80,
                activePatterns: ['affiliate.dashboard'],
            ),
        ];
    }

    public function supports(string $level): bool
    {
        return $level === 'tenant';
    }

    public function priority(): int
    {
        return 10;
    }
}
