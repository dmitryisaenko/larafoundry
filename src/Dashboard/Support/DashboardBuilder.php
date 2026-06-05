<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Dashboard\Support;

use Dmitryisaenko\LaraFoundry\Dashboard\Contracts\DashboardWidgetProviderInterface;
use Dmitryisaenko\LaraFoundry\Navigation\Contracts\PolicyChecker;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuBuilder;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Builds a permission-filtered, sorted widget list for a console level
 * (phase 3.4).
 *
 * The structural twin of {@see MenuBuilder}
 * (decision: the dashboard seam mirrors the navigation seam). It merges widgets
 * from every supporting provider, drops anything the current user may not see,
 * sorts by widget order and emits arrays. Vue only renders what survives, so a
 * gated widget — and its data — never reaches the client.
 *
 * Registered as a singleton; results are memoised per (level, user) for the
 * request, since `build()` is called from the dashboard controller and a widget's
 * data may be expensive to compute.
 *
 * Reuses the navigation {@see PolicyChecker} contract rather than inventing a
 * second one — "can this user see this slug" is the same question on both seams.
 * Widgets are flat (no submenu), so the filter is simpler than the menu builder's.
 */
class DashboardBuilder
{
    /**
     * @var array<int, DashboardWidgetProviderInterface>
     */
    protected array $providers = [];

    protected ?PolicyChecker $policyChecker = null;

    /**
     * Per-request memo of built widget lists, keyed by "level:userId".
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    protected array $cache = [];

    /**
     * Register a provider. Providers are kept ordered by their priority (lower
     * first); the final widget list is still sorted by widget `order`.
     */
    public function addProvider(DashboardWidgetProviderInterface $provider): self
    {
        $this->providers[] = $provider;

        usort($this->providers, static fn (DashboardWidgetProviderInterface $a, DashboardWidgetProviderInterface $b) => $a->priority() <=> $b->priority());

        return $this;
    }

    public function setPolicyChecker(PolicyChecker $checker): self
    {
        $this->policyChecker = $checker;

        return $this;
    }

    /**
     * Build the widget list for a level: collect → filter → sort → serialise.
     *
     * Guests get nothing. The result is memoised for the request.
     *
     * @return array<int, array<string, mixed>>
     */
    public function build(string $level, ?Authenticatable $user = null): array
    {
        $user ??= auth()->user();

        $key = $level.':'.($user?->getAuthIdentifier() ?? 'guest');

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        if ($user === null) {
            return $this->cache[$key] = [];
        }

        $widgets = $this->collectWidgets($level, $user);
        $widgets = $this->filter($widgets, $user);
        $widgets = $this->sort($widgets);

        return $this->cache[$key] = array_map(
            static fn (DashboardWidget $widget) => $widget->toArray(),
            $widgets
        );
    }

    /**
     * Forget memoised lists — for tests, or after a context change.
     */
    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * Merge widgets from every provider that supports the level.
     *
     * @return array<int, DashboardWidget>
     */
    protected function collectWidgets(string $level, Authenticatable $user): array
    {
        $widgets = [];

        foreach ($this->providers as $provider) {
            if ($provider->supports($level)) {
                $widgets = array_merge($widgets, $provider->getWidgets($level, $user));
            }
        }

        return $widgets;
    }

    /**
     * Drop widgets the user may not see.
     *
     * Rules: a hidden widget (`visible=false`) is dropped; a widget with no policy
     * passes the gate; otherwise the {@see PolicyChecker} decides. No recursion —
     * widgets are flat (unlike menu submenus).
     *
     * @param  array<int, DashboardWidget>  $widgets
     * @return array<int, DashboardWidget>
     */
    protected function filter(array $widgets, Authenticatable $user): array
    {
        $survivors = [];

        foreach ($widgets as $widget) {
            if (! $widget->visible) {
                continue;
            }

            if (! $this->passesPolicy($widget, $user)) {
                continue;
            }

            $survivors[] = $widget;
        }

        return $survivors;
    }

    protected function passesPolicy(DashboardWidget $widget, Authenticatable $user): bool
    {
        if ($widget->policy === null || $widget->policy === '') {
            return true;
        }

        if ($this->policyChecker === null) {
            return true;
        }

        return $this->policyChecker->check($user, $widget->policy);
    }

    /**
     * @param  array<int, DashboardWidget>  $widgets
     * @return array<int, DashboardWidget>
     */
    protected function sort(array $widgets): array
    {
        usort($widgets, static fn (DashboardWidget $a, DashboardWidget $b) => $a->order <=> $b->order);

        return $widgets;
    }
}
