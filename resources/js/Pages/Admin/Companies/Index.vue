<script setup>
import { computed, reactive, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { AdminLayout, CompaniesTable, PagePaginator } from '@dmitryisaenko/larafoundry';

/**
 * Super-admin company list (phase 3.3) — the second populated console screen.
 * Super-admin only (gated server-side by `larafoundry.admin`).
 *
 * Row actions go straight back to the admin endpoints; the server is the
 * authority (block policy, the cascade). This page just wires the buttons. The
 * subscription column is read-only — there is no "change plan" action here.
 */
const props = defineProps({
    companies: { type: Object, default: () => ({ data: [] }) },
    filters: { type: Object, default: () => ({}) },
});

const rows = computed(() => props.companies.data ?? []);

const form = reactive({
    search: props.filters.search ?? '',
    subscriptionStatus: props.filters.subscriptionStatus ?? '',
    blockState: props.filters.blockState ?? '',
});

let debounce = null;
watch(form, () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get('/admin/companies', cleaned(), { preserveState: true, replace: true });
    }, 300);
});

function cleaned() {
    return Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''));
}

function view(company) {
    router.get(`/admin/companies/${company.uuid}`);
}

function block(company) {
    router.post(`/admin/companies/${company.uuid}/block`, {}, { preserveScroll: true });
}

function unblock(company) {
    router.post(`/admin/companies/${company.uuid}/unblock`, {}, { preserveScroll: true });
}
</script>

<template>
    <AdminLayout :title="$t('Companies')">
        <div class="space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                <input
                    v-model="form.search"
                    type="search"
                    :placeholder="$t('Search companies')"
                    class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink"
                >
                <select v-model="form.subscriptionStatus" class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                    <option value="">{{ $t('Any subscription') }}</option>
                    <option value="on_trial">{{ $t('On trial') }}</option>
                    <option value="active">{{ $t('Active') }}</option>
                    <option value="expiring">{{ $t('Expiring') }}</option>
                    <option value="expired">{{ $t('Expired') }}</option>
                    <option value="never_activated">{{ $t('Never activated') }}</option>
                </select>
                <select v-model="form.blockState" class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                    <option value="">{{ $t('All statuses') }}</option>
                    <option value="active">{{ $t('Active') }}</option>
                    <option value="blocked">{{ $t('Blocked') }}</option>
                </select>
            </div>

            <div class="rounded-sm border border-border bg-surface p-3">
                <CompaniesTable
                    :companies="rows"
                    @view="view"
                    @block="block"
                    @unblock="unblock"
                />
            </div>

            <PagePaginator />
        </div>
    </AdminLayout>
</template>
