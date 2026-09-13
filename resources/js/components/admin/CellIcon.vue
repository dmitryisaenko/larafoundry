<script setup>
/**
 * A tiny inline SVG glyph for a host extra-column badge (phase 7 seam).
 *
 * Takes either a NAME from the package's short common set (`cellIcons.js`) or
 * the host's OWN glyph as data — `{ path, view_box?, fill?, stroke_width? }`.
 * Shipping your own is the ordinary route: what a switch looks like in one
 * application's console is not the package's business.
 *
 * The path is bound to the `d` ATTRIBUTE, never rendered as markup — that is the
 * line that matters, and `v-html` is forbidden in this file. Sanitising is left
 * to `resolveCellIcon()`: an unusable glyph (unknown name, no path, a path past
 * the length ceiling, a malformed shape) resolves to null and renders NOTHING,
 * so the caller can fall back to the badge caption.
 *
 * Sized in `em`, so the badge's font-size drives the glyph.
 */
import { computed } from 'vue';
import { resolveCellIcon } from './cellIcons.js';

const props = defineProps({
    // A glyph name from the common set, or a host glyph object. Deliberately
    // untyped: `resolveCellIcon` below is the single gate, and a prop type check
    // would print a Vue warning for every malformed cell on every render — the
    // opposite of the quiet degradation this seam promises.
    name: { type: null, default: '' },
});

const icon = computed(() => resolveCellIcon(props.name));
</script>

<template>
    <svg
        v-if="icon"
        width="1em"
        height="1em"
        :viewBox="icon.viewBox"
        :fill="icon.fill"
        :stroke="icon.strokeWidth !== null ? 'currentColor' : null"
        :stroke-width="icon.strokeWidth"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <path :d="icon.path" />
    </svg>
</template>
