<script setup>
/**
 * Account (user-scope) settings (phase 5.1).
 *
 * Renders the current user's own settings from the schema. Consent flags
 * (form=false) are deliberately not here — they are written by the phase-5.3
 * consent flow, not this self-service form.
 */
import { AppLayout, SettingsForm } from '@dmitryisaenko/larafoundry';

defineProps({
    schema: { type: Array, default: () => [] },
    values: { type: Object, default: () => ({}) },
});
</script>

<template>
    <AppLayout>
        <div class="flex flex-col gap-6">
            <h1 class="text-xl font-semibold text-ink">{{ $t('Account settings') }}</h1>

            <div class="rounded-sm border border-border bg-surface p-6">
                <SettingsForm :schema="schema" :values="values" endpoint="/settings/account" />
            </div>
        </div>
    </AppLayout>
</template>
