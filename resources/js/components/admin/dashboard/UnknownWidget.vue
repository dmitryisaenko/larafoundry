<script setup>
/**
 * Graceful fallback widget (phase 3.4).
 *
 * The dashboard page resolves each widget's component NAME through the frontend
 * registry; when a name is unknown — e.g. an add-on shipped a backend widget but
 * the host forgot to import its registrar — the page falls back to this instead
 * of crashing the whole dashboard. It renders the widget's raw data so the
 * operator still sees the figures, just unstyled, and the missing-import is
 * obvious.
 */
import { computed } from 'vue';
import WidgetCard from './WidgetCard.vue';

const props = defineProps({
    titleKey: { type: String, default: '' },
    data: { type: Object, default: () => ({}) },
    meta: { type: Object, default: () => ({}) },
});

const pretty = computed(() => JSON.stringify(props.data ?? {}, null, 2));
</script>

<template>
    <WidgetCard :title="$t(titleKey)" :icon="meta.icon">
        <pre class="overflow-auto rounded-sm bg-surface-muted p-2 text-xs text-ink-soft">{{ pretty }}</pre>
    </WidgetCard>
</template>
