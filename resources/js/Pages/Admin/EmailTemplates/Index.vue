<script setup>
/**
 * Email templates list in the operator console (phase 5.1, two layers in 2b),
 * super-admin only.
 *
 * Lists BOTH layers with a type badge and a filter:
 *  - Transactional (registry codes) — edit-only + fail-closed. Rows show Edit and
 *    Duplicate (a duplicate is always a fresh marketing copy, never a second
 *    code-driven sender), never Delete.
 *  - Marketing (self-contained DB rows) — full CRUD. Rows show Edit, Duplicate and
 *    Delete (through the core themed confirm dialog, not a native prompt).
 *
 * The "New template" button creates a marketing template.
 */
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { AdminLayout, NavIcon, confirm, useT } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    templates: { type: Array, default: () => [] },
});

const t = useT();

// Filter tabs: all / transactional / marketing.
const filter = ref('all');
const filters = [
    { value: 'all', label: 'All' },
    { value: 'transactional', label: 'Transactional' },
    { value: 'marketing', label: 'Marketing' },
];

const visible = computed(() =>
    filter.value === 'all' ? props.templates : props.templates.filter((tpl) => tpl.type === filter.value),
);

function label(tpl) {
    return tpl.name || tpl.code;
}

async function duplicate(tpl) {
    const ok = await confirm({
        variant: 'question',
        title: t('Duplicate template'),
        message: t('A new marketing copy will be created that you can edit.'),
        highlight: label(tpl),
        confirmLabel: t('Duplicate'),
    });

    if (ok) {
        router.post(`/admin/email-templates/${tpl.code}/duplicate`, {}, { preserveScroll: true });
    }
}

async function destroy(tpl) {
    const ok = await confirm({
        variant: 'danger',
        title: t('Delete template'),
        message: t('This marketing template will be permanently deleted.'),
        highlight: label(tpl),
        confirmLabel: t('Delete'),
    });

    if (ok) {
        router.delete(`/admin/email-templates/${tpl.code}`, { preserveScroll: true });
    }
}
</script>

<template>
    <AdminLayout :title="$t('Email templates')">
        <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-ink-soft">
                    {{ $t('Edit the transactional emails the platform sends, and author your own marketing templates.') }}
                </p>
                <Link
                    href="/admin/email-templates/create"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-white"
                >
                    {{ $t('New template') }}
                </Link>
            </div>

            <!-- Type filter -->
            <div class="flex gap-1 border-b border-border">
                <button
                    v-for="tab in filters"
                    :key="tab.value"
                    type="button"
                    class="border-b-2 px-3 py-2 text-sm"
                    :class="filter === tab.value ? 'border-primary text-ink' : 'border-transparent text-ink-soft'"
                    @click="filter = tab.value"
                >
                    {{ $t(tab.label) }}
                </button>
            </div>

            <div class="overflow-x-auto rounded-md border border-border">
                <table class="w-full border-collapse text-sm">
                    <thead class="sticky-head">
                        <tr class="border-b border-border bg-surface-muted text-left text-ink-soft">
                            <th class="px-4 py-2 font-medium">{{ $t('Template') }}</th>
                            <th class="px-4 py-2 font-medium">{{ $t('Type') }}</th>
                            <th class="px-4 py-2 font-medium">{{ $t('Status') }}</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="tpl in visible" :key="tpl.code" class="border-b border-border last:border-0">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <NavIcon name="mail" />
                                    <div class="flex flex-col">
                                        <span class="font-medium text-ink">{{ label(tpl) }}</span>
                                        <span class="text-xs text-ink-soft">{{ tpl.code }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="tpl.type === 'marketing'
                                        ? 'bg-brand-100 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300'
                                        : 'bg-surface-muted text-ink-soft'"
                                >
                                    {{ tpl.type === 'marketing' ? $t('Marketing') : $t('Transactional') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs text-ink-soft">
                                <template v-if="!tpl.is_active">{{ $t('Inactive') }}</template>
                                <template v-else-if="tpl.customized">{{ $t('Customised') }}</template>
                                <template v-else>{{ $t('Default') }}</template>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <Link
                                    :href="`/admin/email-templates/${tpl.code}/edit`"
                                    class="text-xs font-medium text-brand-600 hover:underline"
                                >
                                    {{ $t('Edit') }}
                                </Link>
                                <button
                                    type="button"
                                    class="ml-3 text-xs font-medium text-ink-soft hover:underline"
                                    @click="duplicate(tpl)"
                                >
                                    {{ $t('Duplicate') }}
                                </button>
                                <button
                                    v-if="tpl.deletable"
                                    type="button"
                                    class="ml-3 text-xs font-medium text-danger hover:underline"
                                    @click="destroy(tpl)"
                                >
                                    {{ $t('Delete') }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!visible.length">
                            <td colspan="4" class="px-4 py-6 text-center text-sm text-ink-soft">
                                {{ $t('No templates yet.') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
.sticky-head th {
    position: sticky;
    top: 0;
    z-index: 1;
}
</style>
