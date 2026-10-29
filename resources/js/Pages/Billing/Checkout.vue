<script setup>
/**
 * Owner checkout page (sub-phase B7a).
 *
 * Confirms a single plan + period, optionally applies a promo code or the app-side free
 * month, then POSTs to the process route. Unlike the donor — which faked instant success
 * and computed discounts client-side — this never computes a discount in the browser
 * (decision D-B5-1: the gateway owns the charged amount) and a paid plan redirects to the
 * gateway's hosted checkout (the controller returns the away-redirect). The promo
 * "apply" button only pre-checks the code when the host exposes the validate endpoint;
 * either way the server re-validates on submit, so the button is pure UX.
 */
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { AppLayout } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    plan: { type: Object, required: true },
    period: { type: String, default: 'monthly' },
    currency: { type: String, default: '' },
    company: { type: Object, default: () => ({}) },
    canUseFreeMonth: { type: Boolean, default: false },
    promoEnabled: { type: Boolean, default: false },
});

const form = useForm({
    plan_id: props.plan.id,
    billing_period: props.period === 'yearly' ? 'yearly' : 'monthly',
    promo_code: '',
    use_free_month: false,
});

const promoState = ref(null); // null | true | false
const validatingPromo = ref(false);

// The optional pre-check endpoint; null when the host has not opted it in (the code is
// still validated server-side on submit). Guarded so a missing Ziggy/route never throws.
const promoCheckUrl = (() => {
    try {
        if (typeof route === 'function' && route().has('larafoundry-billing.promo.validate')) {
            return route('larafoundry-billing.promo.validate');
        }
    } catch (e) {
        // Ziggy unavailable or route not registered — fall through to server-side only.
    }

    return null;
})();

const canPreCheckPromo = computed(() => props.promoEnabled && promoCheckUrl !== null);

function money(cents, currency) {
    return `${(Number(cents) / 100).toFixed(2)} ${currency}`;
}

const price = computed(() => props.plan?.prices?.[form.billing_period] ?? null);

// In <script setup> the template-only `$t` is unavailable; the i18n install exposes
// `globalThis.t` for plain JS (same translator), so use that here.
const submitLabel = computed(() => {
    if (form.use_free_month && props.canUseFreeMonth) {
        return t('Start free month');
    }

    return t('Continue to payment');
});

async function checkPromo() {
    if (!form.promo_code.trim() || !canPreCheckPromo.value) {
        return;
    }

    validatingPromo.value = true;

    try {
        await axios.post(promoCheckUrl, { code: form.promo_code });
        promoState.value = true;
    } catch (e) {
        // Anti-oracle: the endpoint returns no detail on failure, so we only flag valid/not.
        promoState.value = false;
    } finally {
        validatingPromo.value = false;
    }
}

function submit() {
    form.post(route('billing.payment.process'));
}
</script>

<template>
    <AppLayout>
        <div class="mx-auto w-full max-w-xl">
            <Link :href="route('billing.pricing')" class="mb-4 inline-block text-sm text-brand-600 hover:text-brand-700">
                &larr; {{ $t('Back to plans') }}
            </Link>

            <div class="rounded-sm border border-border bg-surface p-6">
                <h1 class="mb-1 text-xl font-semibold text-ink">{{ $t('Checkout') }}</h1>
                <p class="mb-6 text-sm text-ink-soft">{{ $t('You are subscribing to') }} {{ $t(plan.name) }}.</p>

                <form class="flex flex-col gap-5" @submit.prevent="submit">
                    <!-- Billing period -->
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink">{{ $t('Billing period') }}</label>
                        <div class="inline-flex rounded-sm border border-border bg-surface p-0.5 text-sm">
                            <button
                                type="button"
                                class="rounded-sm px-3 py-1 transition"
                                :class="form.billing_period === 'monthly' ? 'bg-brand-500 text-white' : 'text-ink-soft'"
                                @click="form.billing_period = 'monthly'"
                            >
                                {{ $t('Monthly') }}
                            </button>
                            <button
                                type="button"
                                class="rounded-sm px-3 py-1 transition"
                                :class="form.billing_period === 'yearly' ? 'bg-brand-500 text-white' : 'text-ink-soft'"
                                @click="form.billing_period = 'yearly'"
                            >
                                {{ $t('Yearly') }}
                            </button>
                        </div>
                        <p v-if="form.errors.billing_period" class="mt-1 text-xs text-danger">{{ form.errors.billing_period }}</p>
                    </div>

                    <!-- Promo code -->
                    <div v-if="promoEnabled && !form.use_free_month">
                        <label for="promo" class="mb-1 block text-sm font-medium text-ink">
                            {{ $t('Promo code') }} <span class="text-ink-soft">({{ $t('optional') }})</span>
                        </label>
                        <div class="flex gap-2">
                            <input
                                id="promo"
                                v-model="form.promo_code"
                                type="text"
                                class="flex-1 rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink"
                                :placeholder="$t('Enter promo code')"
                                @input="promoState = null"
                            >
                            <button
                                v-if="canPreCheckPromo"
                                type="button"
                                class="rounded-sm border border-border px-3 py-2 text-sm text-ink-soft transition hover:bg-surface-subtle disabled:opacity-50"
                                :disabled="!form.promo_code.trim() || validatingPromo"
                                @click="checkPromo"
                            >
                                {{ validatingPromo ? $t('Checking…') : $t('Apply') }}
                            </button>
                        </div>
                        <p v-if="promoState === true" class="mt-1 text-xs text-emerald-700">{{ $t('Promo code applied.') }}</p>
                        <p v-if="promoState === false" class="mt-1 text-xs text-danger">{{ $t('Invalid or expired promo code.') }}</p>
                        <p v-if="form.errors.promo_code" class="mt-1 text-xs text-danger">{{ form.errors.promo_code }}</p>
                    </div>

                    <!-- Free month -->
                    <label v-if="canUseFreeMonth" class="flex items-start gap-2 rounded-sm bg-surface-subtle p-3 text-sm">
                        <input v-model="form.use_free_month" type="checkbox" class="mt-0.5">
                        <span>
                            <span class="font-medium text-ink">{{ $t('Use your free month') }}</span><br>
                            <span class="text-ink-soft">{{ $t('Activate a free month for your company, no card required.') }}</span>
                        </span>
                    </label>
                    <p v-if="form.errors.use_free_month" class="text-xs text-danger">{{ form.errors.use_free_month }}</p>

                    <!-- Total -->
                    <div class="flex items-center justify-between border-t border-border pt-4">
                        <span class="text-sm text-ink-soft">{{ $t('Total') }}</span>
                        <span class="text-lg font-semibold text-ink">
                            <template v-if="form.use_free_month && canUseFreeMonth">{{ $t('Free month') }}</template>
                            <template v-else-if="price !== null">{{ money(price, plan.currency || currency) }}</template>
                            <template v-else>—</template>
                        </span>
                    </div>
                    <p v-if="!form.use_free_month && form.promo_code.trim()" class="-mt-2 text-xs text-ink-soft">
                        {{ $t('Any discount is applied at the secure checkout.') }}
                    </p>
                    <p v-if="form.errors.error" class="text-xs text-danger">{{ form.errors.error }}</p>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600 disabled:opacity-50"
                    >
                        {{ form.processing ? $t('Processing…') : submitLabel }}
                    </button>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
