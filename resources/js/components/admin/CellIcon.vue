<script setup>
/**
 * A tiny inline SVG glyph for a host extra-column badge (phase 7 seam).
 *
 * Pure presentational, and deliberately a CLOSED map (`cellIcons.js`): the host
 * names a glyph ('search', 'audio'), it never ships path data. The path is bound
 * as an attribute (never v-html), so there is no markup-injection surface.
 *
 * An unknown name renders NOTHING — the caller decides the fallback (UsersTable
 * asks `hasCellIcon()` and falls back to the badge caption). Sized in `em`, so
 * the badge's font-size drives the glyph.
 */
import { computed } from 'vue';
import { CELL_ICON_PATHS } from './cellIcons.js';

const props = defineProps({
    name: { type: String, default: '' },
});

const path = computed(() => CELL_ICON_PATHS[props.name] ?? '');
</script>

<template>
    <svg
        v-if="path"
        width="1em"
        height="1em"
        viewBox="0 0 24 24"
        fill="currentColor"
        aria-hidden="true"
    >
        <path :d="path" />
    </svg>
</template>
