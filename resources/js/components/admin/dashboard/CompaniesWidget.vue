<script setup>
/**
 * Companies metric widget (phase 3.4) — totals, recent sign-ups and the
 * subscription-status breakdown. The breakdown mirrors the SubscriptionStatus
 * vocabulary the admin company list already uses, so the dashboard and the list
 * never disagree. Read-only — managing a plan is the billing add-on's job.
 */
import { computed } from 'vue';
import WidgetCard from './WidgetCard.vue';

const props = defineProps({
    titleKey: { type: String, default: '' },
    data: { type: Object, default: () => ({}) },
    meta: { type: Object, default: () => ({}) },
});

const totals = computed(() => [
    { label: 'Total', value: props.data.total ?? 0 },
    { label: 'New this month', value: props.data.new_month ?? 0 },
]);

const subscriptions = computed(() => [
    { label: 'On trial', value: props.data.on_trial ?? 0 },
    { label: 'Active', value: props.data.active ?? 0 },
    { label: 'Expiring', value: props.data.expiring ?? 0 },
    { label: 'Expired', value: props.data.expired ?? 0 },
    { label: 'Never activated', value: props.data.never_activated ?? 0 },
]);
</script>

<template>
    <WidgetCard :title="$t(titleKey)" :icon="meta.icon">
        <dl class="grid grid-cols-2 gap-3">
            <div v-for="stat in totals" :key="stat.label">
                <dd class="text-2xl font-semibold text-ink">{{ stat.value }}</dd>
                <dt class="text-xs text-ink-soft">{{ $t(stat.label) }}</dt>
            </div>
        </dl>

        <h3 class="mt-4 mb-2 text-xs font-semibold uppercase tracking-wide text-ink-soft">
            {{ $t('Subscription') }}
        </h3>
        <dl class="space-y-1 text-sm">
            <div v-for="stat in subscriptions" :key="stat.label" class="flex items-center justify-between">
                <dt class="text-ink-soft">{{ $t(stat.label) }}</dt>
                <dd class="font-medium text-ink">{{ stat.value }}</dd>
            </div>
        </dl>
    </WidgetCard>
</template>
