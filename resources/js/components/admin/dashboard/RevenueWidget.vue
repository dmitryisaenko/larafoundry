<script setup>
/**
 * Revenue dashboard widget (B9) — the paid add-on's panel injected into the core
 * operator dashboard through the widget seam.
 *
 * Multi-currency WITHOUT conversion: per-currency totals across four windows
 * (this month / last month / this year / all time) plus the live active-
 * subscription count. The data comes pre-computed from the backend
 * RevenueMetricsService; this only lays it out and switches the window client-side.
 * Amounts arrive in minor units, so we divide by 100 at render (same convention as
 * the payments console). MRR/ARPU/churn are intentionally absent (deferred until a
 * currency converter exists).
 *
 * Rendered by name through the core dashboard widget registry, so it carries its
 * own card chrome and reuses only the exported NavIcon — no coupling to the core's
 * internal WidgetCard.
 */
import { computed, ref } from 'vue';
import NavIcon from '../../navigation/NavIcon.vue';

const props = defineProps({
    titleKey: { type: String, default: '' },
    data: { type: Object, default: () => ({}) },
    meta: { type: Object, default: () => ({}) },
});

const periods = [
    { key: 'this_month', label: 'This month' },
    { key: 'last_month', label: 'Last month' },
    { key: 'year', label: 'This year' },
    { key: 'all', label: 'All time' },
];

const period = ref('this_month');

const currencies = computed(() => props.data.currencies ?? []);

function money(minorUnits, currency) {
    const amount = (minorUnits ?? 0) / 100;

    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(amount);
    } catch {
        // Unknown/invalid currency code — fall back to a plain number + code.
        return `${amount.toFixed(2)} ${currency}`;
    }
}
</script>

<template>
    <section class="rounded-sm border border-border bg-surface p-4">
        <header class="mb-3 flex items-center gap-2">
            <NavIcon v-if="meta.icon" :name="meta.icon" />
            <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-soft">{{ $t(titleKey) }}</h2>
        </header>

        <div class="mb-3 flex items-baseline gap-2">
            <span class="text-2xl font-semibold text-ink">{{ data.active_subscriptions ?? 0 }}</span>
            <span class="text-xs text-ink-soft">{{ $t('Active subscriptions') }}</span>
        </div>

        <div class="mb-3 flex flex-wrap gap-1">
            <button
                v-for="option in periods"
                :key="option.key"
                type="button"
                class="rounded-sm px-2 py-1 text-xs"
                :class="period === option.key ? 'bg-brand-700 text-white' : 'bg-surface-muted text-ink-soft'"
                @click="period = option.key"
            >
                {{ $t(option.label) }}
            </button>
        </div>

        <ul v-if="currencies.length" class="space-y-1 text-sm">
            <li v-for="row in currencies" :key="row.currency" class="flex items-center justify-between">
                <span class="text-ink-soft">{{ row.currency }}</span>
                <span class="font-medium text-ink">{{ money(row[period], row.currency) }}</span>
            </li>
        </ul>
        <p v-else class="text-sm text-ink-soft">{{ $t('No revenue yet') }}</p>
    </section>
</template>
