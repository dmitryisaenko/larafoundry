<script setup>
/**
 * Legal page editor page (phase 5.3), super-admin only.
 *
 * Thin wrapper: the AdminLayout plus the LegalPageEditor component, which owns the
 * per-locale fields, the publish/version controls and the server-sanitized preview.
 */
import { AdminLayout, LegalPageEditor } from '@dmitryisaenko/larafoundry';
import { Link } from '@inertiajs/vue3';

defineProps({
    page: { type: Object, required: true },
    locales: { type: Array, default: () => [] },
});
</script>

<template>
    <AdminLayout :title="`${$t('Legal pages')} · ${page.slug}`">
        <div class="flex flex-col gap-6">
            <Link href="/admin/legal-pages" class="text-sm text-ink-soft hover:text-ink">
                &larr; {{ $t('Back to legal pages') }}
            </Link>

            <LegalPageEditor :page="page" :locales="locales" />
        </div>
    </AdminLayout>
</template>
