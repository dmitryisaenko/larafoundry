<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Dashboard;

use Dmitryisaenko\LaraFoundry\Billing\Navigation\BillingAdminMenuProvider;
use Dmitryisaenko\LaraFoundry\Dashboard\Contracts\DashboardWidgetProviderInterface;
use Dmitryisaenko\LaraFoundry\Dashboard\Support\DashboardBuilder;
use Dmitryisaenko\LaraFoundry\Dashboard\Support\DashboardWidget;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Injects the revenue widget into the core operator dashboard (B9), the exact
 * mirror of how {@see BillingAdminMenuProvider}
 * adds the add-on's menu items.
 *
 * Implements the CORE dashboard contract and is registered onto the core
 * {@see DashboardBuilder} in the
 * provider's boot — additive, the same way a host would. Priority 10 runs after
 * the core's own provider (0); the widget's own `order` (7) places the revenue
 * panel at the top of the grid. The whole zone is behind the `larafoundry.admin`
 * gate, so the widget carries no per-item policy (matching the core widgets).
 *
 * The title is an i18n key, translated in Vue; the component name (`RevenueWidget`)
 * is resolved through the frontend widget registry the host imports.
 */
final class BillingRevenueWidgetProvider implements DashboardWidgetProviderInterface
{
    public function __construct(
        protected RevenueMetricsService $revenue,
    ) {}

    /**
     * @return array<int, DashboardWidget>
     */
    public function getWidgets(string $level, Authenticatable $user): array
    {
        if (! $this->supports($level)) {
            return [];
        }

        return [
            new DashboardWidget(
                key: 'billing.revenue',
                component: 'RevenueWidget',
                titleKey: 'Revenue',
                data: $this->revenue->summary(),
                order: 7,
                meta: ['icon' => 'activity', 'span' => 2],
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
