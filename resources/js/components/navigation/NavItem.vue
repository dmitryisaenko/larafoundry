<script setup>
/**
 * A single leaf navigation link (phase 2.3).
 *
 * Renders the item the backend already decided is visible — the menu tree is
 * filtered server-side (decision D-nav-a), so this component never sees a link
 * the user may not follow. The label is translated at render via `$t` on the
 * i18n KEY the backend shipped (decision D-nav-c), so a LocaleSwitcher change
 * re-paints it without a reload. The icon is resolved by name (decision
 * D-nav-f).
 *
 * Active state comes from the backend (`item.active`, route-name wildcard,
 * decision D-nav-e).
 */
import { Link } from '@inertiajs/vue3';
import NavIcon from './NavIcon.vue';

defineProps({
    item: { type: Object, required: true },
});
</script>

<template>
    <Link
        :href="item.url"
        class="flex items-center gap-3 rounded-sm px-3 py-2 text-sm font-medium transition"
        :class="item.active
            ? 'bg-brand-50 text-brand-700'
            : 'text-ink-soft hover:bg-surface-subtle hover:text-ink'"
        :aria-current="item.active ? 'page' : undefined"
    >
        <NavIcon :name="item.icon" />
        <span>{{ $t(item.labelKey) }}</span>
    </Link>
</template>
