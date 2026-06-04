<script setup>
/**
 * Read-only subscription status badge for the company console (phase 3.3).
 *
 * Maps the status string from SubscriptionStatus (the backend classifier) to a
 * colour and a translated label. It DESCRIBES the subscription; it never offers
 * to change it — plan/period management is the paid billing add-on. With billing
 * disabled (the free default) every company reads as "never activated", which is
 * honest: there is no subscription to report.
 */
import { computed } from 'vue';

const props = defineProps({
    status: { type: String, default: 'never_activated' },
});

// Status string -> [tailwind classes, i18n key]. English-as-key labels are
// translated by vue-i18n; an unknown status falls back to a neutral badge.
const MAP = {
    on_trial: ['bg-sky-50 text-sky-700', 'On trial'],
    active: ['bg-emerald-50 text-emerald-700', 'Active'],
    expiring: ['bg-amber-50 text-amber-700', 'Expiring'],
    expired: ['bg-rose-50 text-rose-700', 'Expired'],
    never_activated: ['bg-zinc-100 text-zinc-600', 'Never activated'],
};

const entry = computed(() => MAP[props.status] ?? MAP.never_activated);
</script>

<template>
    <span class="rounded-sm px-2 py-0.5 text-xs" :class="entry[0]">{{ $t(entry[1]) }}</span>
</template>
