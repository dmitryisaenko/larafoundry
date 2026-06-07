<script setup>
/**
 * Legal pages list in the operator console (phase 5.3), super-admin only.
 *
 * Lists every legal page the platform ships (resolved from the registry), whether
 * it is published and its current version. Editing happens on the Edit page —
 * pages cannot be created or deleted here (edit-only).
 */
import { AdminLayout, NavIcon } from '@dmitryisaenko/larafoundry';
import { Link } from '@inertiajs/vue3';

defineProps({
    pages: { type: Array, default: () => [] },
});
</script>

<template>
    <AdminLayout :title="$t('Legal pages')">
        <div class="flex flex-col gap-4">
            <p class="text-sm text-ink-soft">
                {{ $t('Edit and publish your Terms, Privacy and Cookie pages. A page is not publicly visible until you publish it.') }}
            </p>

            <ul class="divide-y divide-border rounded-md border border-border">
                <li v-for="page in pages" :key="page.slug" class="flex items-center justify-between gap-3 px-4 py-3">
                    <div class="flex items-center gap-3">
                        <NavIcon name="legal" />
                        <div class="flex flex-col">
                            <span class="text-sm font-medium text-ink">{{ page.slug }}</span>
                            <span class="text-xs text-ink-soft">
                                <template v-if="page.is_published">{{ $t('Published') }} · {{ $t('Version') }} {{ page.version }}</template>
                                <template v-else>{{ $t('Draft') }}</template>
                            </span>
                        </div>
                    </div>
                    <Link
                        :href="`/admin/legal-pages/${page.slug}/edit`"
                        class="rounded-md border border-border px-3 py-1.5 text-sm font-medium text-ink"
                    >
                        {{ $t('Edit') }}
                    </Link>
                </li>
            </ul>
        </div>
    </AdminLayout>
</template>
