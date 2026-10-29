<script setup>
/**
 * Super-admin promo-code console (sub-phase B7b) — the UI over the B5 backend.
 *
 * List + filters + active toggle; create/edit live on their own pages. Gated
 * server-side by `larafoundry.admin`; the server re-authorizes every action.
 */
import { computed, reactive, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { AdminLayout, PagePaginator } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    promo_codes: { type: Object, default: () => ({ data: [] }) },
    filters: { type: Object, default: () => ({}) },
});

const rows = computed(() => props.promo_codes.data ?? []);

const form = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    type: props.filters.type ?? '',
});

let debounce = null;
watch(form, () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get('/admin/promo-codes', cleaned(), { preserveState: true, replace: true });
    }, 350);
});

function cleaned() {
    return Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''));
}

function toggle(code) {
    router.patch(`/admin/promo-codes/${code.id}/toggle`, {}, { preserveScroll: true });
}

function discount(code) {
    if (code.discount_type === 'percentage') {
        return `${code.discount_value}%`;
    }
    return (Number(code.discount_value) / 100).toFixed(2);
}

function uses(code) {
    return code.max_uses === null || code.max_uses === undefined
        ? `${code.used_count} / ∞`
        : `${code.used_count} / ${code.max_uses}`;
}

function label(value) {
    if (!value) {
        return '—';
    }
    const text = String(value);
    return text.charAt(0).toUpperCase() + text.slice(1);
}

const STATUS_CLASSES = {
    active: 'bg-emerald-50 text-emerald-700',
    inactive: 'bg-zinc-100 text-zinc-600',
    expired: 'bg-amber-50 text-amber-700',
    exhausted: 'bg-rose-50 text-rose-700',
};
function statusClasses(status) {
    return STATUS_CLASSES[status] ?? 'bg-zinc-100 text-zinc-600';
}
</script>

<template>
    <AdminLayout :title="$t('Promo codes')">
        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <input v-model="form.search" type="search" :placeholder="$t('Search code')" class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                    <select v-model="form.status" class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                        <option value="">{{ $t('Any status') }}</option>
                        <option value="active">{{ $t('Active') }}</option>
                        <option value="inactive">{{ $t('Inactive') }}</option>
                        <option value="expired">{{ $t('Expired') }}</option>
                        <option value="exhausted">{{ $t('Exhausted') }}</option>
                    </select>
                    <select v-model="form.type" class="rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink">
                        <option value="">{{ $t('Any type') }}</option>
                        <option value="general">{{ $t('General') }}</option>
                        <option value="personal">{{ $t('Personal') }}</option>
                    </select>
                </div>
                <Link href="/admin/promo-codes/create" class="rounded-sm bg-brand-500 px-3 py-2 text-sm text-white transition hover:bg-brand-600">
                    {{ $t('New promo code') }}
                </Link>
            </div>

            <div v-if="rows.length === 0" class="rounded-sm border border-border bg-surface px-4 py-8 text-center text-sm text-ink-soft">
                {{ $t('No promo codes found.') }}
            </div>
            <div v-else class="overflow-x-auto rounded-sm border border-border bg-surface">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs uppercase text-ink-soft">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ $t('Code') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Kind') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Discount') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Uses') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Audience') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Expires') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Status') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ $t('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="code in rows" :key="code.id" class="border-b border-border last:border-0">
                            <td class="px-4 py-3 font-medium text-ink">
                                {{ code.code }}
                                <span v-if="code.description" class="block text-xs font-normal text-ink-faint">{{ code.description }}</span>
                            </td>
                            <td class="px-4 py-3 text-ink-soft">{{ code.kind === 'free_month' ? $t('Free month') : $t('Discount') }}</td>
                            <td class="px-4 py-3 text-ink">{{ code.kind === 'free_month' ? '—' : discount(code) }}</td>
                            <td class="px-4 py-3 text-ink-soft">
                                {{ uses(code) }}
                                <span v-if="code.total_uses !== code.used_count" class="block text-xs text-ink-faint">{{ $t('Redeemed') }}: {{ code.total_uses }}</span>
                            </td>
                            <td class="px-4 py-3 text-ink-soft">
                                <template v-if="code.is_personal">
                                    <span class="rounded-sm bg-indigo-50 px-2 py-0.5 text-xs text-indigo-700">{{ $t('Personal') }}</span>
                                    <span class="block text-xs text-ink-faint">{{ code.user_email ?? '' }}</span>
                                </template>
                                <span v-else>{{ $t('General') }}</span>
                            </td>
                            <td class="px-4 py-3 text-ink-soft">{{ code.expires_at ?? $t('Never') }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-sm px-2 py-0.5 text-xs" :class="statusClasses(code.status)">{{ $t(label(code.status)) }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-3">
                                    <button type="button" class="text-xs text-ink-soft hover:text-ink" @click="toggle(code)">
                                        {{ code.is_active ? $t('Deactivate') : $t('Activate') }}
                                    </button>
                                    <Link :href="`/admin/promo-codes/${code.id}/edit`" class="text-xs text-brand-600 hover:text-brand-700">{{ $t('Edit') }}</Link>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <PagePaginator />
        </div>
    </AdminLayout>
</template>
