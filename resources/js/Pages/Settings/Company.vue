<script setup>
/**
 * Company (company-scope) settings (phase 5.1).
 *
 * Visible to a member with `company.settings.view`; editable only with
 * `company.settings.update` (owners/super-admins bypass). The values belong to
 * the active company — the server resolves the scope, never the client.
 */
import { AppLayout, SettingsForm } from '@dmitryisaenko/larafoundry';

defineProps({
    schema: { type: Array, default: () => [] },
    values: { type: Object, default: () => ({}) },
    canUpdate: { type: Boolean, default: false },
});
</script>

<template>
    <AppLayout>
        <div class="flex flex-col gap-6">
            <h1 class="text-xl font-semibold text-ink">{{ $t('Company settings') }}</h1>

            <div class="rounded-sm border border-border bg-surface p-6">
                <SettingsForm :schema="schema" :values="values" endpoint="/settings/company" :can-edit="canUpdate" />
            </div>
        </div>
    </AppLayout>
</template>
