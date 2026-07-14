<script setup>
import { AdminLayout } from '@dmitryisaenko/larafoundry';

/**
 * Super-admin Promo codes screen (phase 4) — a monetization stub.
 *
 * Promo codes ship in the paid billing add-on, not the free core, so this is an
 * empty state that reserves the console slot. While billing is off, an upsell CTA
 * (`upsell_url`) points the operator at where to obtain the paid add-on; when the
 * add-on is installed it overrides this screen. Gated server-side by
 * `larafoundry.admin` + OTP.
 */
defineProps({
    billing_enabled: { type: Boolean, default: false },
    upsell_url: { type: String, default: null },
});
</script>

<template>
    <AdminLayout :title="$t('Promo codes')">
        <div class="rounded-sm border border-border bg-surface p-10 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-brand-50 text-brand-700">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 0 1 0 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 0 1 0-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z" />
                </svg>
            </div>
            <h2 class="mt-4 text-base font-semibold text-ink">{{ $t('Promo codes') }}</h2>
            <p class="mx-auto mt-2 max-w-md text-sm text-ink-soft">
                {{ $t('Promo codes are part of the paid billing add-on.') }}
            </p>
            <a
                v-if="!billing_enabled && upsell_url"
                :href="upsell_url"
                target="_blank"
                rel="noopener"
                class="mt-6 inline-flex items-center rounded-sm bg-brand-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-800"
            >
                {{ $t('Get the paid billing add-on') }}
            </a>
        </div>
    </AdminLayout>
</template>
