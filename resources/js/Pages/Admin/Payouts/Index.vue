<script setup>
/**
 * Super-admin payout console (Ф4.4).
 *
 * The commission report: per-currency totals by status (NO cross-currency conversion),
 * a filtered/paginated list, bulk payout actions (approve -> mark paid, plus a manual void
 * for refunds), and a CSV export of the current filter. Gated server-side by
 * `larafoundry.admin`. Money arrives in minor units and is formatted here.
 */
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { AdminLayout, PagePaginator } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    commissions: { type: Object, default: () => ({ data: [] }) },
    totals: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    available_affiliates: { type: Array, default: () => [] },
    // "Company" in teams mode, "Customer" in personal mode — decided server-side.
    subject_labels: { type: Object, default: () => ({ title: 'Company', name: 'Company name' }) },
});

const subjectTitle = computed(() => props.subject_labels?.title ?? 'Company');

const form = reactive({
    status: props.filters.status ?? '',
    affiliate_id: props.filters.affiliate_id ?? '',
    currency: props.filters.currency ?? '',
    period: props.filters.period ?? 'all',
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
});

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
        if ((key === 'date_from' || key === 'date_to') && form.period !== 'range') {
            continue;
        }
        params[key] = value;
    }
    return params;
}

function go() {
    router.get('/admin/payouts', cleaned(), { preserveState: true, replace: true });
}

function money(minor, currency) {
    if (minor === null || minor === undefined) {
        return '—';
    }
    return `${(Number(minor) / 100).toFixed(2)} ${currency ?? ''}`.trim();
}

function exportUrl() {
    const params = new URLSearchParams(cleaned());
    return `/admin/payouts/export?${params.toString()}`;
}

// Row selection for the bulk actions.
const selected = ref([]);
const allOnPage = computed(() => props.commissions.data.map((c) => c.id));
const allSelected = computed(() => allOnPage.value.length > 0 && allOnPage.value.every((id) => selected.value.includes(id)));

function toggleAll() {
    selected.value = allSelected.value ? [] : [...allOnPage.value];
}

const reference = ref('');

function act(path, payload = {}) {
    if (!selected.value.length) {
        return;
    }
    router.post(path, { ids: selected.value, ...payload }, {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = [];
            reference.value = '';
        },
    });
}
</script>

<template>
    <AdminLayout :title="$t('Payouts')">
        <div class="space-y-4">
            <h1 class="text-xl font-semibold">{{ $t('Payouts') }}</h1>

            <div>
                <h2 class="text-sm font-medium mb-1">{{ $t('Totals by status') }}</h2>
                <div class="flex flex-wrap gap-3">
                    <div v-for="(t, i) in totals" :key="i" class="rounded border px-3 py-1 text-sm">
                        <span class="uppercase">{{ t.status }}</span>:
                        <span class="font-mono">{{ money(t.total, t.currency) }}</span>
                        <span class="text-gray-500">({{ t.count }})</span>
                    </div>
                    <div v-if="!totals.length" class="text-sm text-gray-500">{{ $t('No commissions found.') }}</div>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <select v-model="form.status" class="input">
                    <option value="">{{ $t('Any status') }}</option>
                    <option value="pending">{{ $t('Pending') }}</option>
                    <option value="approved">{{ $t('Approved') }}</option>
                    <option value="paid">{{ $t('Paid') }}</option>
                    <option value="void">{{ $t('Voided') }}</option>
                </select>
                <select v-model="form.affiliate_id" class="input">
                    <option value="">{{ $t('Any affiliate') }}</option>
                    <option v-for="a in available_affiliates" :key="a.id" :value="a.id">{{ a.code }} {{ a.email }}</option>
                </select>
                <input v-model="form.currency" type="text" maxlength="3" class="input w-24" :placeholder="$t('Currency')" />
                <select v-model="form.period" class="input">
                    <option value="all">{{ $t('All time') }}</option>
                    <option value="this_month">{{ $t('This month') }}</option>
                    <option value="last_month">{{ $t('Last month') }}</option>
                    <option value="year">{{ $t('This year') }}</option>
                    <option value="range">{{ $t('Custom range') }}</option>
                </select>
                <template v-if="form.period === 'range'">
                    <input v-model="form.date_from" type="date" class="input" />
                    <input v-model="form.date_to" type="date" class="input" />
                </template>
                <a :href="exportUrl()" class="btn">{{ $t('Export CSV') }}</a>
            </div>

            <div v-if="selected.length" class="flex flex-wrap items-center gap-2 rounded border p-2">
                <span class="text-sm">{{ selected.length }} {{ $t('selected') }}</span>
                <button class="btn" @click="act('/admin/payouts/approve')">{{ $t('Approve') }}</button>
                <input v-model="reference" type="text" class="input" :placeholder="$t('Payout reference')" />
                <button class="btn" @click="act('/admin/payouts/mark-paid', { reference })">{{ $t('Mark paid') }}</button>
                <button class="btn" @click="act('/admin/payouts/void')">{{ $t('Void') }}</button>
            </div>

            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left">
                        <th><input type="checkbox" :checked="allSelected" @change="toggleAll" /></th>
                        <th>{{ $t('Affiliate') }}</th>
                        <th>{{ $t(subjectTitle) }}</th>
                        <th>{{ $t('Amount') }}</th>
                        <th>{{ $t('Basis') }}</th>
                        <th>{{ $t('Status') }}</th>
                        <th>{{ $t('Date') }}</th>
                        <th>{{ $t('Reference') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in commissions.data" :key="c.id" class="border-t">
                        <td><input type="checkbox" :value="c.id" v-model="selected" /></td>
                        <td class="font-mono">{{ c.affiliate_code }} <span class="text-gray-500">{{ c.affiliate_email }}</span></td>
                        <td>#{{ c.company_id }}</td>
                        <td class="font-mono">{{ money(c.amount, c.currency) }}</td>
                        <td>{{ c.basis }}</td>
                        <td class="uppercase">{{ c.status }}</td>
                        <td>{{ c.created_at }}</td>
                        <td>{{ c.payout_reference }}</td>
                    </tr>
                    <tr v-if="!commissions.data.length">
                        <td colspan="8" class="py-4 text-center text-gray-500">{{ $t('No commissions found.') }}</td>
                    </tr>
                </tbody>
            </table>

            <PagePaginator :pagination="pagination" />
        </div>
    </AdminLayout>
</template>
