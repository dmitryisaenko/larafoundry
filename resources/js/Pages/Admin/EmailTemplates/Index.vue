<script setup>
/**
 * Email templates list in the operator console (phase 5.1), super-admin only.
 *
 * Lists every template the platform ships (resolved from the registry), whether
 * it is active and whether a super-admin has customised it. Editing happens on
 * the Edit page — templates cannot be created or deleted here (edit-only).
 */
import { AdminLayout, NavIcon } from '@dmitryisaenko/larafoundry';
import { Link } from '@inertiajs/vue3';

defineProps({
    templates: { type: Array, default: () => [] },
});
</script>

<template>
    <AdminLayout :title="$t('Email templates')">
        <div class="flex flex-col gap-4">
            <p class="text-sm text-ink-soft">
                {{ $t('Edit the subject and body of the emails the platform sends. Defaults are used until you customise a template.') }}
            </p>

            <ul class="divide-y divide-border rounded-md border border-border">
                <li v-for="template in templates" :key="template.code" class="flex items-center justify-between gap-3 px-4 py-3">
                    <div class="flex items-center gap-3">
                        <NavIcon name="mail" />
                        <div class="flex flex-col">
                            <span class="text-sm font-medium text-ink">{{ template.code }}</span>
                            <span class="text-xs text-ink-soft">
                                <template v-if="!template.is_active">{{ $t('Inactive') }} · </template>
                                <template v-if="template.customized">{{ $t('Customised') }}</template>
                                <template v-else>{{ $t('Default') }}</template>
                            </span>
                        </div>
                    </div>
                    <Link
                        :href="`/admin/email-templates/${template.code}/edit`"
                        class="rounded-md border border-border px-3 py-1.5 text-sm font-medium text-ink"
                    >
                        {{ $t('Edit') }}
                    </Link>
                </li>
            </ul>
        </div>
    </AdminLayout>
</template>
