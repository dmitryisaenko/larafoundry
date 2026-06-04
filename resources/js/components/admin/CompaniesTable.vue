<script setup>
/**
 * Super-admin company list table (phase 3.3).
 *
 * Renders the rows shaped by AdminCompanyResource and emits row actions up to
 * the page (view/block/unblock), which own the requests. The subscription
 * column is READ-ONLY — a status badge, not a control: managing a plan is the
 * paid billing add-on, not the console. No payment sums are shown (the core does
 * not store them). No v-html anywhere.
 */
import CompaniesTableActions from './CompaniesTableActions.vue';
import CompanyLogo from '../media/CompanyLogo.vue';
import SubscriptionStatusBadge from './SubscriptionStatusBadge.vue';

defineProps({
    companies: { type: Array, default: () => [] },
});

defineEmits(['view', 'block', 'unblock']);
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-border text-xs uppercase tracking-wide text-ink-soft">
                <tr>
                    <th class="px-3 py-2">{{ $t('Company') }}</th>
                    <th class="px-3 py-2">{{ $t('Owner') }}</th>
                    <th class="px-3 py-2">{{ $t('Country') }}</th>
                    <th class="px-3 py-2">{{ $t('Employees') }}</th>
                    <th class="px-3 py-2">{{ $t('Subscription') }}</th>
                    <th class="px-3 py-2">{{ $t('Created') }}</th>
                    <th class="px-3 py-2">{{ $t('Status') }}</th>
                    <th class="px-3 py-2 text-right">{{ $t('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="company in companies" :key="company.uuid" class="border-b border-border/60 align-middle">
                    <td class="px-3 py-2">
                        <div class="flex items-center gap-2">
                            <CompanyLogo :src="company.logo_url" :name="company.name" :size="32" />
                            <div class="font-medium text-ink">{{ company.name }}</div>
                        </div>
                    </td>
                    <td class="px-3 py-2 text-ink-soft">
                        <template v-if="company.owner">
                            <div>{{ company.owner.name }} {{ company.owner.lastname }}</div>
                            <div class="text-xs">{{ company.owner.email }}</div>
                        </template>
                        <span v-else>—</span>
                    </td>
                    <td class="px-3 py-2 text-ink-soft">{{ company.country || '—' }}</td>
                    <td class="px-3 py-2 text-ink-soft">{{ company.employees_count ?? 0 }}</td>
                    <td class="px-3 py-2">
                        <SubscriptionStatusBadge :status="company.subscription?.status" />
                    </td>
                    <td class="px-3 py-2 text-ink-soft">{{ company.created_date || '—' }}</td>
                    <td class="px-3 py-2">
                        <span v-if="company.is_blocked" class="rounded-sm bg-amber-50 px-2 py-0.5 text-xs text-amber-700">{{ $t('Blocked') }}</span>
                        <span v-else class="rounded-sm bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">{{ $t('Active') }}</span>
                    </td>
                    <td class="px-3 py-2 text-right">
                        <CompaniesTableActions
                            :company="company"
                            @view="$emit('view', company)"
                            @block="$emit('block', company)"
                            @unblock="$emit('unblock', company)"
                        />
                    </td>
                </tr>
                <tr v-if="!companies.length">
                    <td colspan="8" class="px-3 py-6 text-center text-ink-soft">{{ $t('No companies found') }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
