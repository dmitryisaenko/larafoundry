<script setup>
/**
 * Super-admin: edit a draft broadcast (phase 4.1). Super-admin only (gated
 * server-side). Only drafts reach here usefully — the server refuses to update
 * a sent one.
 */
import { AdminLayout, BroadcastForm } from '@dmitryisaenko/larafoundry';

defineProps({
    notification: { type: Object, required: true },
    types: { type: Array, default: () => [] },
    available_locales: { type: Array, default: () => ['en'] },
});
</script>

<template>
    <AdminLayout :title="$t('Edit broadcast')">
        <BroadcastForm :notification="notification" :types="types" :available-locales="available_locales" />
    </AdminLayout>
</template>
