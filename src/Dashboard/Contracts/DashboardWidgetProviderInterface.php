<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Dashboard\Contracts;

use Dmitryisaenko\LaraFoundry\Dashboard\Support\DashboardBuilder;
use Dmitryisaenko\LaraFoundry\Dashboard\Support\DashboardWidget;
use Dmitryisaenko\LaraFoundry\Navigation\Contracts\MenuProviderInterface;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contributes dashboard widgets for a given console level (phase 3.4).
 *
 * The exact mirror of the navigation {@see MenuProviderInterface}
 * (decision: the dashboard seam copies the menu seam 1:1). The core ships a
 * provider for its own FREE metrics (users / companies / activity); the paid
 * billing add-on registers a revenue provider the same additive way it registers
 * its admin menu — so the dashboard grows without the core knowing anything about
 * revenue.
 *
 * The one difference from the menu contract: {@see getWidgets()} receives the
 * resolved user. A menu item is static markup the builder can compute once; a
 * widget carries computed DATA (counts, sums), and a provider may scope those
 * figures to the viewer, so it needs the user at build time.
 */
interface DashboardWidgetProviderInterface
{
    /**
     * The widgets this provider contributes for the given level.
     *
     * Visibility / permission filtering is the {@see DashboardBuilder}'s
     * job — a provider just declares its widgets (with their data already
     * computed). Titles are i18n keys (English-as-key), NOT translated strings,
     * exactly like menu labels: the Vue layer translates at render so a locale
     * switch re-paints the dashboard without a reload.
     *
     * @param  string  $level  console level, e.g. 'admin'
     * @return array<int, DashboardWidget>
     */
    public function getWidgets(string $level, Authenticatable $user): array;

    /**
     * Whether this provider contributes anything to the given level.
     */
    public function supports(string $level): bool;

    /**
     * Provider ordering across the merged set (lower runs first). Widget-level
     * `order` still sorts the final list; this only orders provider merge.
     */
    public function priority(): int;
}
