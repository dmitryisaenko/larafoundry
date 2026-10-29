<script setup>
/**
 * Partner affiliate dashboard (Ф4.4).
 *
 * The partner's own referral code/link, referral count and per-currency earnings by
 * status — everything is self-scoped server-side. In self_serve eligibility a not-yet
 * enrolled user gets an opt-in; in admin eligibility a neutral "not enrolled" state.
 * Imports come from the core package barrel so the page works once published into a host.
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { AppLayout } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    enrolled: { type: Boolean, default: false },
    eligibility: { type: String, default: 'auto' },
    can_opt_in: { type: Boolean, default: false },
    awaiting_approval: { type: Boolean, default: false },
    active: { type: Boolean, default: false },
    affiliate: { type: Object, default: () => ({}) },
    referrals_count: { type: Number, default: 0 },
    earnings: { type: Array, default: () => [] },
});

const copied = ref(false);

function copyLink() {
    if (props.affiliate?.link && navigator.clipboard) {
        navigator.clipboard.writeText(props.affiliate.link);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    }
}

function optIn() {
    router.post('/affiliate/opt-in', {}, { preserveScroll: true });
}

function money(minor, currency) {
    return `${(Number(minor) / 100).toFixed(2)} ${currency ?? ''}`.trim();
}
</script>

<template>
    <AppLayout :title="$t('Affiliate program')">
        <div class="max-w-3xl mx-auto space-y-6 p-4">
            <h1 class="text-2xl font-semibold">{{ $t('Affiliate program') }}</h1>

            <div v-if="!enrolled" class="rounded border p-4 space-y-3">
                <p v-if="can_opt_in">{{ $t('Join the affiliate program') }}</p>
                <p v-else>{{ $t('You are not part of the affiliate program.') }}</p>
                <button v-if="can_opt_in" class="btn btn-primary" @click="optIn">{{ $t('Join the affiliate program') }}</button>
            </div>

            <template v-else>
                <div v-if="awaiting_approval" class="rounded border border-amber-300 bg-amber-50 p-3">
                    {{ $t('Your application is awaiting approval.') }}
                </div>

                <div class="rounded border p-4 space-y-2">
                    <div class="text-sm text-gray-500">{{ $t('Your referral link') }}</div>
                    <div class="flex items-center gap-2">
                        <code class="flex-1 break-all">{{ affiliate.link }}</code>
                        <button class="btn" @click="copyLink">{{ copied ? $t('Copied') : $t('Copy') }}</button>
                    </div>
                    <div class="text-sm">{{ $t('Code') }}: <span class="font-mono">{{ affiliate.code }}</span></div>
                </div>

                <div class="rounded border p-4">
                    <div class="text-sm text-gray-500">{{ $t('Referrals') }}</div>
                    <div class="text-2xl font-semibold">{{ referrals_count }}</div>
                </div>

                <div class="rounded border p-4">
                    <h2 class="font-medium mb-2">{{ $t('Earnings') }}</h2>
                    <table v-if="earnings.length" class="w-full text-sm">
                        <thead>
                            <tr class="text-left">
                                <th>{{ $t('Currency') }}</th>
                                <th>{{ $t('Status') }}</th>
                                <th>{{ $t('Amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(e, i) in earnings" :key="i" class="border-t">
                                <td>{{ e.currency }}</td>
                                <td class="uppercase">{{ e.status }}</td>
                                <td class="font-mono">{{ money(e.total, e.currency) }} ({{ e.count }})</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="text-sm text-gray-500">{{ $t('No earnings yet.') }}</p>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
