<script setup>
/**
 * Coloured status pill for a ticket (phase 4.2).
 *
 * The label is an i18n key (`tickets.status.<slug>`), so the wording is
 * translated and a host can override it. An unknown status falls back to the
 * neutral style and shows the raw slug.
 */
import { computed } from 'vue';

const props = defineProps({
    status: { type: String, default: '' },
});

const CLASS = {
    'wait-moderator': 'bg-amber-100 text-amber-800',
    'wait-customer': 'bg-brand-100 text-brand-800',
    resolved: 'bg-emerald-100 text-emerald-800',
};

const pillClass = computed(() => CLASS[props.status] ?? 'bg-surface-accent text-ink-soft');
const labelKey = computed(() => `tickets.status.${props.status}`);
</script>

<template>
    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="pillClass">
        {{ $t(labelKey) }}
    </span>
</template>
