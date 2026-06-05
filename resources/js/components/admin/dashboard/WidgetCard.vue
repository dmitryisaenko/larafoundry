<script setup>
/**
 * Shared shell for an operator-dashboard widget (phase 3.4).
 *
 * A framed card with a translated title and optional icon. The core's widgets
 * (and an add-on's, e.g. the billing revenue widget) drop their body into the
 * default slot, so every widget looks consistent without each one re-declaring
 * the chrome. Internal to the dashboard widget set — not part of the package
 * barrel.
 */
import NavIcon from '../../navigation/NavIcon.vue';

defineProps({
    title: { type: String, default: '' },
    icon: { type: String, default: null },
});
</script>

<template>
    <section class="rounded-sm border border-border bg-surface p-4">
        <header class="mb-3 flex items-center gap-2">
            <NavIcon v-if="icon" :name="icon" />
            <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-soft">{{ title }}</h2>
        </header>
        <slot />
    </section>
</template>
