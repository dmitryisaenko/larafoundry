<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Navigation\Support;

use Illuminate\Support\Facades\Route;

/**
 * One navigation entry (phase 2.3).
 *
 * A plain DTO a provider returns; the {@see MenuBuilder} filters and sorts a
 * collection of these, then serialises the survivors to arrays for Inertia.
 * Two-level menus are supported via {@see $submenu}.
 *
 * Differences from the donor sketch (kohana_legacy LayoutDataService /
 * Architecture.md), all deliberate:
 *  - {@see $labelKey} is an i18n KEY, not a translated string. `toArray()`
 *    ships the key and the Vue layer runs `$t()` at render (decision D-nav-c),
 *    so a LocaleSwitcher change (phase 2.2) re-paints the menu live. The donor
 *    baked `__($label)` on the backend, freezing the language until reload.
 *  - {@see $icon} is a NAME string mapped to an inline SVG in Vue (decision
 *    D-nav-f), never a published asset URL — that keeps icons out of `vendor/`
 *    publishing (frontend risk #1).
 *  - Active state is computed on the backend from route-name wildcard patterns
 *    (decision D-nav-e), defaulting to `{route}*`.
 */
class MenuItem
{
    /**
     * @param  string  $labelKey  i18n key (English-as-key), translated in Vue
     * @param  string|null  $route  route name (mutually exclusive-ish with $url)
     * @param  string|null  $url  explicit URL when there is no route name
     * @param  string|null  $policy  permission slug guarding visibility (null = always show)
     * @param  string|null  $icon  icon name resolved to inline SVG in Vue
     * @param  int  $order  sort key, lower = higher in the list
     * @param  bool  $visible  hard hide regardless of policy
     * @param  array<int, MenuItem>  $submenu  nested items (one level deep)
     * @param  array<string, mixed>  $meta  free-form metadata (badges, etc.)
     * @param  list<string>|null  $activePatterns  route-name wildcards for active
     *                                             state; null = `["{route}*"]`
     */
    public function __construct(
        public string $labelKey,
        public ?string $route = null,
        public ?string $url = null,
        public ?string $policy = null,
        public ?string $icon = null,
        public int $order = 100,
        public bool $visible = true,
        public array $submenu = [],
        public array $meta = [],
        public ?array $activePatterns = null,
    ) {}

    /**
     * Resolve the destination URL: an explicit URL wins, else the named route.
     *
     * A route name that is not registered yields an empty string rather than
     * throwing, so one unbuilt host route cannot blank the whole menu.
     */
    public function resolveUrl(): string
    {
        if ($this->url !== null) {
            return $this->url;
        }

        if ($this->route !== null && Route::has($this->route)) {
            return route($this->route);
        }

        return '';
    }

    /**
     * Whether the current request matches this item (route-name wildcard).
     *
     * Defaults to `{route}*` when no explicit patterns are given. URL-only items
     * (no route name, no patterns) are never auto-active.
     */
    public function isActive(): bool
    {
        $patterns = $this->activePatterns
            ?? ($this->route !== null ? [$this->route.'*'] : []);

        if ($patterns === []) {
            return false;
        }

        return Route::currentRouteNamed(...$patterns);
    }

    /**
     * Serialise for the frontend. Ships the label KEY, not a translation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'labelKey' => $this->labelKey,
            'url' => $this->resolveUrl(),
            'routeName' => $this->route,
            'policy' => $this->policy,
            'icon' => $this->icon,
            'order' => $this->order,
            'active' => $this->isActive(),
            'visible' => $this->visible,
            'submenu' => array_map(static fn (MenuItem $item) => $item->toArray(), $this->submenu),
            'meta' => $this->meta,
        ];
    }
}
