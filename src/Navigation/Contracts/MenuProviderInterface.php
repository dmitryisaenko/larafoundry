<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Navigation\Contracts;

use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuItem;

/**
 * Contributes menu items for a given navigation level (phase 2.3).
 *
 * The core ships providers for its own surfaces (admin console, tenant
 * sidebar); a host registers its own providers to grow the menu without
 * editing the core — the same additive-merge idiom used for permissions
 * (phase 1.3) and activity-log events (phase 2.1). Decision D-nav-b: a PHP
 * provider, not a config array, so items can be conditional, badged or
 * computed.
 */
interface MenuProviderInterface
{
    /**
     * The menu items this provider contributes for the given level.
     *
     * Visibility/permission filtering is the {@see MenuBuilder}'s job — a
     * provider just declares its items. Labels are i18n keys (English-as-key),
     * NOT translated strings (decision D-nav-c): the Vue layer translates at
     * render so a locale switch re-paints the menu without a reload.
     *
     * The builder clones items before pruning their submenus, so returning
     * cached/shared instances is safe; still, returning fresh instances per call
     * is the simplest contract.
     *
     * @param  string  $level  one of 'admin' | 'tenant'
     * @return array<int, MenuItem>
     */
    public function getMenuItems(string $level): array;

    /**
     * Whether this provider contributes anything to the given level.
     */
    public function supports(string $level): bool;

    /**
     * Provider ordering across the merged set (lower runs first). Item-level
     * `order` still sorts the final list; this only orders provider merge.
     */
    public function priority(): int;
}
