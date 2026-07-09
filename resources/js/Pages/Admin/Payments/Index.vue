<script setup>
import { AdminLayout } from '@dmitryisaenko/larafoundry';

/**
 * Super-admin Payments screen (phase 7) — a stub over the core billing seam.
 *
 * The core ships no payment records of its own, so this is an empty state until
 * a host wires a gateway (or installs the paid billing add-on, which brings its
 * own console). `billing_enabled` flips the copy between "no gateway yet" and
 * "wired, nothing yet". Gated server-side by `larafoundry.admin` + OTP.
 */
defineProps({
    billing_enabled: { type: Boolean, default: false },
});
</script>

<template>
    <AdminLayout :title="$t('Payments')">
        <div class="rounded-sm border border-border bg-surface p-10 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-brand-50 text-brand-700">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
                </svg>
            </div>
            <h2 class="mt-4 text-base font-semibold text-ink">{{ $t('Payments') }}</h2>
            <p class="mx-auto mt-2 max-w-md text-sm text-ink-soft">
                {{ billing_enabled ? $t('No payments yet.') : $t('Payments will appear here once a payment gateway is connected.') }}
            </p>
        </div>
    </AdminLayout>
</template>
