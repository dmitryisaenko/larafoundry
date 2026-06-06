<script setup>
/**
 * Super-admin: new broadcast (phase 4.1). Super-admin only (gated server-side
 * by `larafoundry.admin`). The form component owns the submit; this page just
 * frames it in the console layout.
 */
import { AdminLayout, BroadcastForm } from '@dmitryisaenko/larafoundry';

defineProps({
    types: { type: Array, default: () => [] },
    available_locales: { type: Array, default: () => ['en'] },
});
</script>

<template>
    <AdminLayout :title="$t('New broadcast')">
        <BroadcastForm :types="types" :available-locales="available_locales" />
    </AdminLayout>
</template>
