<script setup>
/**
 * Email template editor page (phase 5.1, two layers in 2b), super-admin only.
 *
 * Branches on the template layer:
 *  - transactional → the leaner {@link EmailTemplateEditor} (subject/body + active,
 *    no code/name/delete — fail-closed);
 *  - marketing → {@link MarketingEmailTemplateForm} in edit mode (also name +
 *    variable whitelist, plus delete).
 */
import { AdminLayout, EmailTemplateEditor, MarketingEmailTemplateForm } from '@dmitryisaenko/larafoundry';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    template: { type: Object, required: true },
    locales: { type: Array, default: () => [] },
});

const isMarketing = props.template.type === 'marketing';
</script>

<template>
    <AdminLayout :title="`${$t('Email templates')} · ${template.name || template.code}`">
        <div class="flex flex-col gap-6">
            <Link href="/admin/email-templates" class="text-sm text-ink-soft hover:text-ink">
                &larr; {{ $t('Back to templates') }}
            </Link>

            <MarketingEmailTemplateForm
                v-if="isMarketing"
                :template="template"
                :locales="locales"
                mode="edit"
            />
            <EmailTemplateEditor v-else :template="template" :locales="locales" />
        </div>
    </AdminLayout>
</template>
