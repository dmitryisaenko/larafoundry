<script setup>
/**
 * Owner payment history (sub-phase B7a).
 *
 * A read-only ledger of the company's recorded payments. The gateway payload is never
 * sent here (the resource omits `payment_response`); money arrives in minor units and is
 * formatted in the browser. Status colours mirror the core SubscriptionStatusBadge
 * palette for a consistent look once published into a host.
 */
import { Link } from '@inertiajs/vue3';
import { AppLayout } from '@dmitryisaenko/larafoundry';

defineProps({
    payments: { type: Array, default: () => [] },
    company: { type: Object, default: () => ({}) },
});

function money(cents, currency) {
    if (cents === null || cents === undefined) {
        return '—';
    }

    return `${(Number(cents) / 100).toFixed(2)} ${currency ?? ''}`.trim();
}

function formatDate(iso) {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString();
}

// Capitalise a lowercase slug ("pro" -> "Pro", "monthly" -> "Monthly") so the dictionary
// keys (which are sentence-case) resolve; an unknown value degrades gracefully.
function label(value) {
    if (!value) {
        return '—';
    }

    const text = String(value);

    return text.charAt(0).toUpperCase() + text.slice(1);
}

const STATUS_CLASSES = {
    success: 'bg-emerald-50 text-emerald-700',
    pending: 'bg-amber-50 text-amber-700',
    failed: 'bg-rose-50 text-rose-700',
};

function statusClasses(status) {
    return STATUS_CLASSES[status] ?? 'bg-zinc-100 text-zinc-600';
}
</script>

<template>
    <AppLayout>
        <div class="mx-auto w-full max-w-4xl">
            <div class="mb-6 flex items-center justify-between">
                <h1 class="text-xl font-semibold text-ink">{{ $t('Payment history') }}</h1>
                <Link :href="route('billing.pricing')" class="text-sm text-brand-600 hover:text-brand-700">
                    {{ $t('Plans & pricing') }}
                </Link>
            </div>

            <div v-if="payments.length === 0" class="rounded-sm border border-border bg-surface px-4 py-8 text-center text-sm text-ink-soft">
                {{ $t('No payments yet.') }}
            </div>

            <div v-else class="overflow-x-auto rounded-sm border border-border bg-surface">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs uppercase text-ink-soft">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ $t('Date') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Plan') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Period') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Amount') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Discount') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Total') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="payment in payments" :key="payment.id" class="border-b border-border last:border-0">
                            <td class="px-4 py-3 text-ink-soft">{{ formatDate(payment.created_at) }}</td>
                            <td class="px-4 py-3 text-ink">{{ $t(label(payment.plan_id)) }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ $t(label(payment.billing_period)) }}</td>
                            <td class="px-4 py-3 text-ink">{{ money(payment.amount, payment.currency) }}</td>
                            <td class="px-4 py-3 text-ink-soft">
                                <span v-if="payment.discount_amount > 0">-{{ money(payment.discount_amount, payment.currency) }}</span>
                                <span v-else>—</span>
                            </td>
                            <td class="px-4 py-3 font-medium text-ink">{{ money(payment.total, payment.currency) }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-sm px-2 py-0.5 text-xs" :class="statusClasses(payment.payment_status)">
                                    {{ $t(label(payment.payment_status)) }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
