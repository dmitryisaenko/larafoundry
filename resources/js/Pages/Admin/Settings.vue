<script setup>
/**
 * App-scope settings in the operator console (phase 5.1), super-admin only.
 *
 * The whole page sits behind the `larafoundry.admin` gate; it renders the
 * app-scope schema and writes to the admin settings endpoint.
 */
import { AdminLayout, SettingsForm } from '@dmitryisaenko/larafoundry';

defineProps({
    schema: { type: Array, default: () => [] },
    values: { type: Object, default: () => ({}) },
});
</script>

<template>
    <AdminLayout :title="$t('Settings')">
        <SettingsForm :schema="schema" :values="values" endpoint="/admin/settings" />
    </AdminLayout>
</template>
