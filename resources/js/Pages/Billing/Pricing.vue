<script setup>
/**
 * Owner pricing page (sub-phase B7a).
 *
 * Lists the available plans priced in the tenant's own currency (resolved server-side —
 * the page never picks a currency) and links each to checkout. A soft alert surfaces the
 * GetBlockStatusAction warning (expiring / unpaid) without locking anything; the hard
 * lockout is the blocked page's job. Imports come from the core package barrel so the
 * page works once published into a host (frontend tech-risk #1: vendor/ is never scanned).
 */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { AppLayout } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    plans: { type: Array, default: () => [] },
    currency: { type: String, default: '' },
    company: { type: Object, default: () => ({}) },
    blockStatus: { type: Object, default: () => ({}) },
    canUseFreeMonth: { type: Boolean, default: false },
    gatewayConfigured: { type: Boolean, default: true },
});

const period = ref(props.company.billing_period === 'yearly' ? 'yearly' : 'monthly');

const priceFor = (plan) => plan?.prices?.[period.value] ?? null;
const isPriced = (plan) => priceFor(plan) !== null;

function money(cents, currency) {
    return `${(Number(cents) / 100).toFixed(2)} ${currency}`;
}

// Humanise an entitlement slug ("core.access" -> "Core access") so the dictionary can
// translate it; an unknown slug degrades to the humanised English rather than the slug.
function featureLabel(slug) {
    const text = String(slug).replace(/[._]+/g, ' ').trim();

    return text.charAt(0).toUpperCase() + text.slice(1);
}

const expiresSoon = computed(() => props.blockStatus?.subscription_expires_soon === true);
const daysLeft = computed(() => props.blockStatus?.days_until_expiry ?? null);
</script>

<template>
    <AppLayout>
        <div class="mx-auto w-full max-w-4xl">
            <h1 class="mb-1 text-xl font-semibold text-ink">{{ $t('Plans & pricing') }}</h1>
            <p class="mb-6 text-sm text-ink-soft">{{ $t('Choose the plan that fits your team.') }}</p>

            <div
                v-if="expiresSoon"
                class="mb-4 rounded-sm border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
            >
                {{ $t('Your subscription is expiring soon.') }}
                <span v-if="daysLeft !== null">{{ $t('Days remaining') }}: {{ daysLeft }}</span>
            </div>

            <div
                v-if="!gatewayConfigured"
                class="mb-4 rounded-sm border border-border bg-surface-subtle px-4 py-3 text-sm text-ink-soft"
            >
                {{ $t('Online payment is not available right now. Please contact support.') }}
            </div>

            <div class="mb-6 inline-flex rounded-sm border border-border bg-surface p-0.5 text-sm">
                <button
                    type="button"
                    class="rounded-sm px-3 py-1 transition"
                    :class="period === 'monthly' ? 'bg-brand-500 text-white' : 'text-ink-soft'"
                    @click="period = 'monthly'"
                >
                    {{ $t('Monthly') }}
                </button>
                <button
                    type="button"
                    class="rounded-sm px-3 py-1 transition"
                    :class="period === 'yearly' ? 'bg-brand-500 text-white' : 'text-ink-soft'"
                    @click="period = 'yearly'"
                >
                    {{ $t('Yearly') }}
                </button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="plan in plans"
                    :key="plan.id"
                    class="flex flex-col rounded-sm border border-border bg-surface p-5"
                    :class="{ 'ring-1 ring-brand-500': plan.id === company.plan_id }"
                >
                    <div class="mb-2 flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-ink">{{ $t(plan.name) }}</h2>
                        <span
                            v-if="plan.id === company.plan_id"
                            class="rounded-sm bg-brand-50 px-2 py-0.5 text-xs text-brand-700"
                        >
                            {{ $t('Current plan') }}
                        </span>
                    </div>

                    <div class="mb-4 text-2xl font-bold text-ink">
                        <template v-if="isPriced(plan)">
                            {{ money(priceFor(plan), plan.currency) }}
                            <span class="text-sm font-normal text-ink-soft">
                                / {{ period === 'monthly' ? $t('per month') : $t('per year') }}
                            </span>
                        </template>
                        <template v-else>{{ $t('Free') }}</template>
                    </div>

                    <ul v-if="plan.features?.length" class="mb-5 flex-1 space-y-1 text-sm text-ink-soft">
                        <li v-for="feature in plan.features" :key="feature">{{ $t(featureLabel(feature)) }}</li>
                    </ul>
                    <div v-else class="mb-5 flex-1" />

                    <Link
                        v-if="isPriced(plan)"
                        :href="route('billing.checkout', { plan: plan.id, period })"
                        class="rounded-sm bg-brand-500 px-4 py-2 text-center text-sm text-white transition hover:bg-brand-600"
                    >
                        {{ $t('Choose plan') }}
                    </Link>
                    <span v-else class="rounded-sm bg-surface-subtle px-4 py-2 text-center text-sm text-ink-soft">
                        {{ $t('Included') }}
                    </span>
                </div>
            </div>

            <p v-if="canUseFreeMonth" class="mt-6 text-sm text-ink-soft">
                {{ $t('A free month is available for your company at checkout, no card required.') }}
            </p>
        </div>
    </AppLayout>
</template>
