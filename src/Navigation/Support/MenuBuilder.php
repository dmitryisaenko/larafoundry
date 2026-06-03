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
     * @param  array<int, MenuItem>  $items
     * @return array<int, MenuItem>
     */
    protected function sort(array $items): array
    {
        usort($items, static fn (MenuItem $a, MenuItem $b) => $a->order <=> $b->order);

        return $items;
    }
}
