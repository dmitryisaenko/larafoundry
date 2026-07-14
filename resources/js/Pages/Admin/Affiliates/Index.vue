<script setup>
import { AdminLayout } from '@dmitryisaenko/larafoundry';

/**
 * Super-admin Affiliates screen (phase 4) — a monetization stub.
 *
 * The affiliate program ships in the paid billing add-on, not the free core, so
 * this is an empty state that reserves the console slot. While billing is off, an
 * upsell CTA (`upsell_url`) points the operator at where to obtain the paid
 * add-on; when the add-on is installed it overrides this screen. Gated
 * server-side by `larafoundry.admin` + OTP.
 */
defineProps({
    billing_enabled: { type: Boolean, default: false },
    upsell_url: { type: String, default: null },
});
</script>

<template>
    <AdminLayout :title="$t('Affiliates')">
        <div class="rounded-sm border border-border bg-surface p-10 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-brand-50 text-brand-700">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z" />
                </svg>
            </div>
            <h2 class="mt-4 text-base font-semibold text-ink">{{ $t('Affiliates') }}</h2>
            <p class="mx-auto mt-2 max-w-md text-sm text-ink-soft">
                {{ $t('The affiliate program is part of the paid billing add-on.') }}
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
