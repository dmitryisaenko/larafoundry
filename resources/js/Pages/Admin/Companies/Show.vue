<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { AdminLayout, CompanyLogo, SubscriptionStatusBadge } from '@dmitryisaenko/larafoundry';

/**
 * Super-admin company detail (phase 3.3) — read-only.
 * Super-admin only (gated server-side by `larafoundry.admin`).
 *
 * Shows the company's owner, members count and subscription snapshot. The only
 * action is block/unblock (the cascade); there is no plan management here — that
 * is the paid billing add-on. Subscription dates are shown as the core stores
 * them, with no payment history (the core does not keep payment records).
 */
const props = defineProps({
    company: { type: Object, default: () => ({ data: {} }) },
});

const data = computed(() => props.company.data ?? {});

function block() {
    router.post(`/admin/companies/${data.value.uuid}/block`, {}, { preserveScroll: true });
}

function unblock() {
    router.post(`/admin/companies/${data.value.uuid}/unblock`, {}, { preserveScroll: true });
}
</script>

<template>
    <AdminLayout :title="data.name">
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <CompanyLogo :src="data.logo_url" :name="data.name" :size="48" />
                    <div>
                        <h1 class="text-lg font-semibold text-ink">{{ data.name }}</h1>
                        <p class="text-sm text-ink-soft">{{ data.country || '—' }}</p>
                    </div>
                </div>
                <button
                    v-if="!data.is_blocked"
                    type="button"
                    class="rounded-sm bg-amber-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-amber-700"
                    @click="block"
                >
                    {{ $t('Block company') }}
                </button>
                <button
                    v-else
                    type="button"
                    class="rounded-sm bg-emerald-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                    @click="unblock"
                >
                    {{ $t('Unblock company') }}
                </button>
            </div>

            <div v-if="data.is_blocked" class="rounded-sm border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <span class="font-medium">{{ $t('Blocked') }}.</span>
                <span v-if="data.blocked_reason"> {{ data.blocked_reason }}</span>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-sm border border-border bg-surface p-4">
                    <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-soft">{{ $t('Owner') }}</h2>
                    <template v-if="data.owner">
                        <div class="text-ink">{{ data.owner.name }} {{ data.owner.lastname }}</div>
                        <div class="text-sm text-ink-soft">{{ data.owner.email }}</div>
                    </template>
                    <p v-else class="text-sm text-ink-soft">—</p>
                    <p class="mt-3 text-sm text-ink-soft">{{ $t('Employees') }}: {{ data.employees_count ?? 0 }}</p>
                </div>

                <div class="rounded-sm border border-border bg-surface p-4">
                    <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-soft">{{ $t('Subscription') }}</h2>
                    <SubscriptionStatusBadge :status="data.subscription?.status" />
                    <dl class="mt-3 space-y-1 text-sm text-ink-soft">
                        <div class="flex justify-between gap-4">
                            <dt>{{ $t('Plan') }}</dt>
                            <dd class="text-ink">{{ data.subscription?.plan_id || '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt>{{ $t('Trial ends') }}</dt>
                            <dd class="text-ink">{{ data.subscription?.trial_ends_at || '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt>{{ $t('Subscription ends') }}</dt>
                            <dd class="text-ink">{{ data.subscription?.subscription_ends_at || '—' }}</dd>
                        </div>
                    </dl>
                    <p class="mt-3 text-xs text-ink-soft">{{ $t('Subscription management lives in the billing add-on.') }}</p>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
