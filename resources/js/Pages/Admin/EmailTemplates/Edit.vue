<script setup>
/**
 * Email template editor page (phase 5.1), super-admin only.
 *
 * Thin wrapper: the AdminLayout plus the EmailTemplateEditor component, which
 * owns the per-locale fields, the variable list, the server-rendered preview and
 * the test-email send.
 */
import { AdminLayout, EmailTemplateEditor } from '@dmitryisaenko/larafoundry';
import { Link } from '@inertiajs/vue3';

defineProps({
    template: { type: Object, required: true },
    locales: { type: Array, default: () => [] },
});
</script>

<template>
    <AdminLayout :title="`${$t('Email templates')} · ${template.code}`">
        <div class="flex flex-col gap-6">
            <Link href="/admin/email-templates" class="text-sm text-ink-soft hover:text-ink">
                &larr; {{ $t('Back to templates') }}
            </Link>

            <EmailTemplateEditor :template="template" :locales="locales" />
        </div>
    </AdminLayout>
</template>
