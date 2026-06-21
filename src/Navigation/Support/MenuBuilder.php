<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Navigation\Support;

use Dmitryisaenko\LaraFoundry\Navigation\Contracts\MenuProviderInterface;
use Dmitryisaenko\LaraFoundry\Navigation\Contracts\PolicyChecker;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Builds a permission-filtered, sorted menu tree for a navigation level
 * (phase 2.3).
 *
 * Decision D-nav-a: the menu is built AND filtered on the backend. The builder
 * merges items from every supporting provider, drops anything the current user
 * may not see (recursively, including submenus), sorts by item order and emits
 * arrays. Vue only renders what survives, so hidden links and routes never
 * reach the client (unlike a frontend permission filter).
 *
 * Registered as a singleton; results are memoised per (level, user) for the
 * lifetime of the request, since `build()` is called from shared Inertia props
 * on every response.
 */
class MenuBuilder
{
    /**
     * @var array<int, MenuProviderInterface>
     */
    protected array $providers = [];

    protected ?PolicyChecker $policyChecker = null;

    /**
     * Per-request memo of built trees, keyed by "level:userId".
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    protected array $cache = [];

    /**
     * Register a provider. Providers are kept ordered by their priority (lower
     * first); the final item list is still sorted by item `order`.
     */
    public function addProvider(MenuProviderInterface $provider): self
    {
        $this->providers[] = $provider;

        usort($this->providers, static fn (MenuProviderInterface $a, MenuProviderInterface $b) => $a->priority() <=> $b->priority());

        return $this;
    }

    public function setPolicyChecker(PolicyChecker $checker): self
    {
        $this->policyChecker = $checker;

        return $this;
    }

    /**
     * Build the menu tree for a level: collect → filter → sort → serialise.
     *
     * Guests get an empty menu. The result is memoised for the request.
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

        $items = $this->collectItems($level);
        $items = $this->mergeGroups($items);
        $items = $this->filter($items, $user);
        $items = $this->sort($items);

        return $this->cache[$key] = array_map(
            static fn (MenuItem $item) => $item->toArray(),
            $items
        );
    }

    /**
     * Forget memoised trees — for tests, or after a context change (e.g. the
     * active company switched mid-request).
     */
    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * Merge items from every provider that supports the level.
     *
     * @return array<int, MenuItem>
     */
    protected function collectItems(string $level): array
    {
        $items = [];

        foreach ($this->providers as $provider) {
            if ($provider->supports($level)) {
                $items = array_merge($items, $provider->getMenuItems($level));
            }
        }

        return $items;
    }

    /**
     * Merge top-level PURE groups (no own route/url) that share a labelKey into
     * one, concatenating their submenus.
     *
     * This lets the core and the host grow the SAME sidebar group from separate
     * providers: the core's TenantMenuProvider ships a "My company" group with
     * Employees/Roles/Company settings, and the host adds a "My company" group
     * carrying its Dashboard child — both land under one parent instead of
     * rendering as two identically-named groups.
     *
     * Only pure groups merge. Two leaf items with the same labelKey are distinct
     * links and are left untouched. The merged group keeps the LOWEST `order`
     * among the merged copies (so a host with order 0 can pull the shared group
     * to the top); the merged submenu is later sorted by the normal `sort()`.
     * Runs BEFORE filter/sort so policy filtering and the empty-group drop see
     * the fully merged submenu. Originals are cloned, never mutated in place.
     *
     * @param  array<int, MenuItem>  $items
     * @return array<int, MenuItem>
     */
    protected function mergeGroups(array $items): array
    {
        /** @var array<string, MenuItem> $groups  merged pure-group accumulator, keyed by labelKey */
        $groups = [];
        $result = [];

        foreach ($items as $item) {
            $isPureGroup = $item->route === null
                && $item->url === null
                && $item->submenu !== [];

            if (! $isPureGroup) {
                $result[] = $item;

                continue;
            }

            if (! isset($groups[$item->labelKey])) {
                // First copy of this group — clone it (and reassign its own
                // submenu array) so we never mutate the provider's instance.
                // The same object instance is held in both $groups (for folding
                // later copies into) and $result (to keep output order), so
                // mutating it via $groups is visible in $result.
                $clone = clone $item;
                $clone->submenu = $item->submenu;
                $groups[$item->labelKey] = $clone;
                $result[] = $clone;

                continue;
            }

            // Subsequent copy — fold its submenu into the shared instance and
            // keep the lowest order so any provider can pull the group up.
            $existing = $groups[$item->labelKey];
            $existing->submenu = array_merge($existing->submenu, $item->submenu);
            $existing->order = min($existing->order, $item->order);
        }

        return $result;
    }

    /**
     * Recursively drop items the user may not see.
     *
     * Rules: a hidden item (`visible=false`) is dropped; an item with no policy
     * passes the policy gate; otherwise the {@see PolicyChecker} decides.
     * Submenus are filtered too, and a parent that has NO own destination and
     * an EMPTY surviving submenu is dropped (an unreachable empty group).
     *
     * @param  array<int, MenuItem>  $items
     * @return array<int, MenuItem>
     */
    protected function filter(array $items, Authenticatable $user): array
    {
        $survivors = [];

        foreach ($items as $item) {
            if (! $item->visible) {
                continue;
            }

            if (! $this->passesPolicy($item, $user)) {
                continue;
            }

            // Clone before pruning the submenu: a host provider may return shared
            // or cached MenuItem instances, and mutating those in place would let
            // one user's filtered subset leak into another's. The clone is shallow
            // but we reassign its own submenu, so the original is left intact.
            if ($item->submenu !== []) {
                $filtered = $this->filter($item->submenu, $user);

                // A pure group (no own link) with nothing left under it is noise.
                if ($filtered === [] && $item->route === null && $item->url === null) {
                    continue;
                }

                $item = clone $item;
                $item->submenu = $filtered;
            }

            $survivors[] = $item;
        }

        return $survivors;
    }

    protected function passesPolicy(MenuItem $item, Authenticatable $user): bool
    {
        if ($item->policy === null || $item->policy === '') {
            return true;
        }

        if ($this->policyChecker === null) {
            return true;
        }

        return $this->policyChecker->check($user, $item->policy);
    }

    /**
     * Sort items by `order` ascending, recursively into submenus.
     *
     * Recursion matters once groups are merged: a group's children can come from
     * different providers (e.g. host Dashboard order 10 + core Employees order
     * 80), so the submenu must be re-sorted by order, not left in merge order.
     *
     * @param  array<int, MenuItem>  $items
     * @return array<int, MenuItem>
     */
    protected function sort(array $items): array
    {
        usort($items, static fn (MenuItem $a, MenuItem $b) => $a->order <=> $b->order);

        foreach ($items as $item) {
            if ($item->submenu !== []) {
                $item->submenu = $this->sort($item->submenu);
            }
        }

        return $items;
    }
}
