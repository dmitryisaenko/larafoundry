<script setup>
/**
 * Users metric widget (phase 3.4) — totals, recent sign-ups, verification /
 * activity / block counts. Data comes pre-computed from the backend
 * DashboardMetricsService; this only lays it out.
 */
import { computed } from 'vue';
import WidgetCard from './WidgetCard.vue';

const props = defineProps({
    titleKey: { type: String, default: '' },
    data: { type: Object, default: () => ({}) },
    meta: { type: Object, default: () => ({}) },
});

const stats = computed(() => [
    { label: 'Total', value: props.data.total ?? 0 },
    { label: 'New today', value: props.data.new_today ?? 0 },
    { label: 'New this month', value: props.data.new_month ?? 0 },
    { label: 'New this year', value: props.data.new_year ?? 0 },
    { label: 'Verified', value: props.data.verified ?? 0 },
    { label: 'Active', value: props.data.active ?? 0 },
    { label: 'Blocked', value: props.data.blocked ?? 0 },
]);
</script>

<template>
    <WidgetCard :title="$t(titleKey)" :icon="meta.icon">
        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div v-for="stat in stats" :key="stat.label">
                <dd class="text-2xl font-semibold text-ink">{{ stat.value }}</dd>
                <dt class="text-xs text-ink-soft">{{ $t(stat.label) }}</dt>
            </div>
        </dl>
    </WidgetCard>
</template>
