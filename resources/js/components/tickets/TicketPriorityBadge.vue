<script setup>
/**
 * Coloured priority pill for a ticket (phase 4.2).
 *
 * Label is an i18n key (`tickets.priority.<slug>`). 'high' stands out; anything
 * else (standard / host-added) uses the neutral style.
 */
import { computed } from 'vue';

const props = defineProps({
    priority: { type: String, default: '' },
});

const pillClass = computed(() =>
    props.priority === 'high'
        ? 'bg-danger-100 text-danger'
        : 'bg-surface-accent text-ink-soft'
);
const labelKey = computed(() => `tickets.priority.${props.priority}`);
</script>

<template>
    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="pillClass">
        {{ $t(labelKey) }}
    </span>
</template>
