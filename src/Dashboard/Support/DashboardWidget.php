<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Dashboard\Support;

use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuItem;

/**
 * One dashboard widget (phase 3.4).
 *
 * A plain DTO a provider returns; the {@see DashboardBuilder} filters and sorts a
 * collection of these, then serialises the survivors to arrays for Inertia. The
 * counterpart of {@see MenuItem},
 * with two deliberate differences:
 *
 *  - {@see $component} names a Vue component (resolved through the frontend
 *    widget registry) instead of being rendered by one shared NavItem. Different
 *    widgets render completely different shapes — a stat grid, a feed, a revenue
 *    panel — so the backend ships a component NAME and the frontend maps it.
 *  - {@see $data} carries the computed metrics for that component. A menu item is
 *    static; a widget is data.
 *
 * {@see $titleKey} is an i18n KEY (English-as-key), translated in Vue at render
 * (same rule as MenuItem::$labelKey) so a locale switch re-paints live.
 */
class DashboardWidget
{
    /**
     * @param  string  $key  stable widget id (e.g. 'core.users'); also the Vue list key
     * @param  string  $component  Vue component name resolved by the frontend registry
     * @param  string  $titleKey  i18n key (English-as-key), translated in Vue
     * @param  array<string, mixed>  $data  computed metrics for the component
     * @param  int  $order  sort key, lower = earlier in the grid
     * @param  string|null  $policy  permission slug guarding visibility (null = always show)
     * @param  bool  $visible  hard hide regardless of policy
     * @param  array<string, mixed>  $meta  free-form metadata (icon, grid span, …)
     */
    public function __construct(
        public string $key,
        public string $component,
        public string $titleKey,
        public array $data = [],
        public int $order = 100,
        public ?string $policy = null,
        public bool $visible = true,
        public array $meta = [],
    ) {}

    /**
     * Serialise for the frontend. Ships the title KEY, not a translation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'component' => $this->component,
            'titleKey' => $this->titleKey,
            'data' => $this->data,
            'order' => $this->order,
            'policy' => $this->policy,
            'visible' => $this->visible,
            'meta' => $this->meta,
        ];
    }
}
