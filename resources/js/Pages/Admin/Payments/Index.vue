<script setup>
/**
 * Super-admin payments console (sub-phase B7b).
 *
 * Read-only: a filtered, paginated list of every company's payments with per-currency
 * totals. There is NO currency conversion (that is B9) — each currency is shown
 * separately. The gateway response is the allowlisted, PII-free safe subset and is a
 * super-admin-only disclosure. Gated server-side by `larafoundry.admin`; this page just
 * wires filters and sort.
 */
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { AdminLayout, PagePaginator } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    payments: { type: Object, default: () => ({ data: [] }) },
    totals: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    available_countries: { type: [Array, Object], default: () => [] },
    available_plans: { type: Array, default: () => [] },
    available_promo_codes: { type: Array, default: () => [] },
    // What to call the payer: "Company" in teams mode, "Customer" in personal mode
    // (where every row is a person). Server-decided — the tenancy mode is server state.
    subject_labels: { type: Object, default: () => ({ title: 'Company', name: 'Company name' }) },
});

const rows = computed(() => props.payments.data ?? []);
const subjectTitle = computed(() => props.subject_labels?.title ?? 'Company');
const subjectNameLabel = computed(() => props.subject_labels?.name ?? 'Company name');

const form = reactive({
    period: props.filters.period ?? 'this_month',
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
    country: props.filters.country ?? '',
    payment_status: props.filters.payment_status ?? '',
    plan_id: props.filters.plan_id ?? '',
    email: props.filters.email ?? '',
    company_name: props.filters.company_name ?? '',
    promo_code_id: props.filters.promo_code_id ?? '',
});

const sortBy = ref(props.filters.sort_by ?? 'paid_at');
const sortOrder = ref(props.filters.sort_order ?? 'desc');

const countryList = computed(() => {
    const list = props.available_countries;
    if (Array.isArray(list)) {
        return list.map((c) => (typeof c === 'string' ? { code: c, label: c } : c));
    }
    return Object.entries(list ?? {}).map(([code, label]) => ({ code, label }));
});
const hasCountryList = computed(() => countryList.value.length > 0);

let debounce = null;
watch(form, () => {
    clearTimeout(debounce);
    debounce = setTimeout(go, 350);
});

function cleaned() {
    const params = {};
    for (const [key, value] of Object.entries(form)) {
        if (value === '' || value === null) {
            continue;
        }
        // The manual range fields only matter when the range period is selected.
        if ((key === 'date_from' || key === 'date_to') && form.period !== 'range') {
            continue;
        }
        params[key] = value;
    }
    params.sort_by = sortBy.value;
    params.sort_order = sortOrder.value;
    return params;
}

function go() {
    router.get('/admin/payments', cleaned(), { preserveState: true, replace: true });
}

function sort(column) {
    if (sortBy.value === column) {
        sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortBy.value = column;
        sortOrder.value = 'desc';
    }
    go();
}

function money(cents, currency) {
    if (cents === null || cents === undefined) {
        return '—';
    }
    return `${(Number(cents) / 100).toFixed(2)} ${currency ?? ''}`.trim();
}

// Capitalise a lowercase slug so the sentence-case dictionary keys resolve.
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

const openResponse = ref(null);
function toggleResponse(id) {
    openResponse.value = openResponse.value === id ? null : id;
}
</script>

<template>
    <AdminLayout :title="$t('Payments')">
        <div class="space-y-4">
            <!-- Filters -->
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <select v-model="form.period" class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                    <option value="this_month">{{ $t('This month') }}</option>
                    <option value="last_month">{{ $t('Last month') }}</option>
                    <option value="year">{{ $t('This year') }}</option>
                    <option value="all">{{ $t('All time') }}</option>
                    <option value="range">{{ $t('Custom range') }}</option>
                </select>
                <template v-if="form.period === 'range'">
                    <input v-model="form.date_from" type="date" class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                    <input v-model="form.date_to" type="date" class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                </template>

                <select v-model="form.payment_status" class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                    <option value="">{{ $t('Any status') }}</option>
                    <option value="success">{{ $t('Success') }}</option>
                    <option value="failed">{{ $t('Failed') }}</option>
                </select>

                <select v-model="form.plan_id" class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                    <option value="">{{ $t('Any plan') }}</option>
                    <option v-for="plan in available_plans" :key="plan.id" :value="plan.id">{{ $t(plan.name) }}</option>
                </select>

                <select v-model="form.promo_code_id" class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                    <option value="">{{ $t('Any promo code') }}</option>
                    <option v-for="code in available_promo_codes" :key="code.id" :value="code.id">{{ code.code }}</option>
                </select>

                <select v-if="hasCountryList" v-model="form.country" class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                    <option value="">{{ $t('Any country') }}</option>
                    <option v-for="c in countryList" :key="c.code" :value="c.code">{{ c.label }}</option>
                </select>
                <input v-else v-model="form.country" type="text" maxlength="2" :placeholder="$t('Country code')" class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">

                <input v-model="form.email" type="search" :placeholder="$t('Payer email')" class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                <input v-model="form.company_name" type="search" :placeholder="$t(subjectNameLabel)" class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
            </div>

            <!-- Per-currency totals (no conversion) -->
            <div v-if="totals.length" class="flex flex-wrap items-center gap-2">
                <span class="text-xs uppercase text-ink-soft">{{ $t('Totals (per currency)') }}:</span>
                <span
                    v-for="t in totals"
                    :key="t.currency + (t.is_failed ? '-f' : '-s')"
                    class="rounded-sm px-2 py-1 text-sm font-medium"
                    :class="t.is_failed ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700'"
                >
                    {{ money(t.total, t.currency) }}
                </span>
            </div>

            <!-- Table -->
            <div v-if="rows.length === 0" class="rounded-sm border border-border bg-surface px-4 py-8 text-center text-sm text-ink-soft">
                {{ $t('No payments found.') }}
            </div>
            <div v-else class="overflow-x-auto rounded-sm border border-border bg-surface">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs uppercase text-ink-soft">
                        <tr>
                            <th class="cursor-pointer px-4 py-3 font-medium" @click="sort('paid_at')">{{ $t('Date') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t(subjectTitle) }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Payer') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Plan') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Total') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Promo code') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Status') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Details') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="payment in rows" :key="payment.id">
                            <tr class="border-b border-border last:border-0" :class="payment.is_latest_for_company ? 'bg-brand-50/40' : ''">
                                <td class="whitespace-nowrap px-4 py-3 text-ink-soft">
                                    {{ payment.paid_at_date }}<br><span class="text-xs text-ink-faint">{{ payment.paid_at_time }}</span>
                                </td>
                                <td class="px-4 py-3 text-ink">
                                    {{ payment.company_name ?? '—' }}
                                    <span v-if="payment.company_country" class="text-xs text-ink-faint">{{ payment.company_country }}</span>
                                </td>
                                <td class="px-4 py-3 text-ink-soft">
                                    <span class="text-ink">{{ payment.user_fullname || '—' }}</span><br>
                                    <span class="text-xs">{{ payment.user_email ?? '—' }}</span>
                                </td>
                                <td class="px-4 py-3 text-ink">
                                    {{ payment.plan_name ? $t(payment.plan_name) : '—' }}
                                    <span class="block text-xs text-ink-faint">{{ $t(label(payment.billing_period)) }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="font-medium text-ink">{{ money(payment.total_amount, payment.currency) }}</span>
                                    <span v-if="payment.discount_amount > 0" class="block text-xs text-emerald-700">-{{ money(payment.discount_amount, payment.currency) }}</span>
                                </td>
                                <td class="px-4 py-3 text-ink-soft">{{ payment.promo_code ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded-sm px-2 py-0.5 text-xs" :class="statusClasses(payment.payment_status)">
                                        {{ $t(label(payment.payment_status)) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <button
                                        v-if="payment.payment_response"
                                        type="button"
                                        class="text-xs text-brand-600 hover:text-brand-700"
                                        @click="toggleResponse(payment.id)"
                                    >
                                        {{ openResponse === payment.id ? $t('Hide') : $t('Gateway data') }}
                                    </button>
                                    <span v-else class="text-xs text-ink-faint">—</span>
                                </td>
                            </tr>
                            <tr v-if="openResponse === payment.id" class="border-b border-border bg-surface-subtle">
                                <td colspan="8" class="px-4 py-3">
                                    <pre class="overflow-x-auto whitespace-pre-wrap break-all text-xs text-ink-soft">{{ JSON.stringify(payment.payment_response, null, 2) }}</pre>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <PagePaginator />
        </div>
    </AdminLayout>
</template>
