<script setup>
/**
 * Create a marketing email template (phase 2b), super-admin only.
 *
 * Thin wrapper: the AdminLayout plus the shared MarketingEmailTemplateForm in
 * create mode. Transactional templates are never created here — they arrive by
 * adding a registry entry + a Notification.
 */
import { AdminLayout, MarketingEmailTemplateForm } from '@dmitryisaenko/larafoundry';
import { Link } from '@inertiajs/vue3';

defineProps({
    locales: { type: Array, default: () => [] },
});
</script>

<template>
    <AdminLayout :title="`${$t('Email templates')} · ${$t('New template')}`">
        <div class="flex flex-col gap-6">
            <Link href="/admin/email-templates" class="text-sm text-ink-soft hover:text-ink">
                &larr; {{ $t('Back to templates') }}
            </Link>

            <h1 class="text-xl font-semibold text-ink">{{ $t('New marketing template') }}</h1>

            <MarketingEmailTemplateForm :template="null" :locales="locales" mode="create" />
        </div>
    </AdminLayout>
</template>
