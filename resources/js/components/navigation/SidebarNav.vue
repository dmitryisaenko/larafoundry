<script setup>
/**
 * Renders a filtered menu tree as a vertical sidebar (phase 2.3).
 *
 * Two levels deep: a leaf is a {@see NavItem}; a group (an item with a
 * `submenu`) renders a heading and its children, expanded by default and
 * collapsible. The tree is already filtered and sorted by the backend
 * {@see MenuBuilder} — this component is pure presentation.
 */
import { ref } from 'vue';
import NavItem from './NavItem.vue';
import NavIcon from './NavIcon.vue';

const props = defineProps({
    items: { type: Array, default: () => [] },
    // Icon-rail mode: hide labels, centre glyphs, and flatten groups to their
    // leaves (a rail cannot expand a sub-menu) so no link is ever hidden.
    collapsed: { type: Boolean, default: false },
});

// Groups (items carrying a submenu) start open; a click toggles them. Keyed by
// the group's label key, which is unique within a level in practice. (Distinct
// from the `collapsed` prop, which is the whole-rail icon-only mode.)
const groupCollapsed = ref({});

function toggle(key) {
    groupCollapsed.value[key] = !groupCollapsed.value[key];
}

function isGroup(item) {
    return Array.isArray(item.submenu) && item.submenu.length > 0;
}
</script>

<template>
    <nav class="flex flex-col gap-1" aria-label="Sidebar">
        <template v-for="item in items" :key="item.labelKey">
            <!-- Leaf link -->
            <NavItem v-if="!isGroup(item)" :item="item" :collapsed="collapsed" />

            <!-- Group, icon-rail mode: flatten to its leaves (no expandable header) -->
            <template v-else-if="collapsed">
                <NavItem v-for="child in item.submenu" :key="child.labelKey" :item="child" collapsed />
            </template>

            <!-- Group with children (expanded sidebar) -->
            <div v-else>
                <button
                    type="button"
                    class="flex w-full items-center justify-between rounded-sm px-3 py-2 text-sm font-semibold text-ink-soft transition hover:bg-surface-subtle hover:text-ink"
                    @click="toggle(item.labelKey)"
                >
                    <span class="flex items-center gap-3">
                        <NavIcon :name="item.icon" />
                        <span>{{ $t(item.labelKey) }}</span>
                    </span>
                    <span class="text-xs">{{ groupCollapsed[item.labelKey] ? '▸' : '▾' }}</span>
                </button>
                <div v-show="!groupCollapsed[item.labelKey]" class="ml-4 mt-1 flex flex-col gap-1 border-l border-border pl-2">
                    <NavItem v-for="child in item.submenu" :key="child.labelKey" :item="child" />
                </div>
            </div>
        </template>
    </nav>
</template>
