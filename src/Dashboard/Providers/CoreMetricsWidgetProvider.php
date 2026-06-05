<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Dashboard\Providers;

use Dmitryisaenko\LaraFoundry\Dashboard\Contracts\DashboardWidgetProviderInterface;
use Dmitryisaenko\LaraFoundry\Dashboard\Support\DashboardMetricsService;
use Dmitryisaenko\LaraFoundry\Dashboard\Support\DashboardWidget;
use Dmitryisaenko\LaraFoundry\Navigation\Providers\AdminMenuProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The FREE core's dashboard widgets for the super-admin operator console
 * (phase 3.4).
 *
 * Populates the 'admin' level with the three metrics every multi-tenant SaaS
 * has out of the box: Users, Companies and recent Activity. The whole admin zone
 * already sits behind the `larafoundry.admin` gate (super-admin via
 * VisitorStatus), so these widgets carry NO permission slug — the zone gate is
 * the authority, exactly like {@see AdminMenuProvider}.
 *
 * Revenue is deliberately absent: it is the paid billing add-on's widget (B9),
 * registered through the same seam at a higher priority. Titles are i18n keys
 * (English-as-key), translated in Vue.
 */
class CoreMetricsWidgetProvider implements DashboardWidgetProviderInterface
{
    public function __construct(
        protected DashboardMetricsService $metrics,
    ) {}

    public function getWidgets(string $level, Authenticatable $user): array
    {
        if (! $this->supports($level)) {
            return [];
        }

        return [
            new DashboardWidget(
                key: 'core.users',
                component: 'UsersWidget',
                titleKey: 'Users',
                data: $this->metrics->users(),
                order: 10,
                meta: ['icon' => 'users'],
            ),
            new DashboardWidget(
                key: 'core.companies',
                component: 'CompaniesWidget',
                titleKey: 'Companies',
                data: $this->metrics->companies(),
                order: 20,
                meta: ['icon' => 'companies'],
            ),
            new DashboardWidget(
                key: 'core.activity',
                component: 'ActivityWidget',
                titleKey: 'Recent activity',
                data: $this->metrics->activity(),
                order: 30,
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
        return 0;
    }
}
