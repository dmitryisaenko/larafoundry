<script setup>
/**
 * Operator: edit a ticket's fields (phase 4.2).
 *
 * Unlike the user side, the operator may set the status directly (constrained to
 * the workflow slugs); a status change is audited server-side. Submits PUT to
 * the admin update endpoint.
 */
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { AdminLayout, InputField, SelectField, TextareaField } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    ticket: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    labels: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
});

const form = useForm({
    title: props.ticket.title ?? '',
    message: props.ticket.message ?? '',
    priority: props.ticket.priority ?? 'standard',
    status: props.ticket.status ?? 'wait-moderator',
    categories: [...(props.ticket.categories ?? [])],
    labels: [...(props.ticket.labels ?? [])],
});

const priorityOptions = computed(() => props.priorities.map((p) => ({ value: p, name: `tickets.priority.${p}` })));
const statusOptions = computed(() => props.statuses.map((s) => ({ value: s, name: `tickets.status.${s}` })));

function toggle(field, slug) {
    const set = new Set(form[field]);
    set.has(slug) ? set.delete(slug) : set.add(slug);
    form[field] = [...set];
}

function submit() {
    form.put(`/admin/tickets/${props.ticket.id}`);
}
</script>

<template>
    <AdminLayout :title="$t('Support')">
        <div class="mx-auto w-full max-w-2xl space-y-4">
            <div class="flex items-center justify-between">
                <h1 class="text-lg font-semibold text-ink">{{ $t('Edit') }}</h1>
                <Link :href="`/admin/tickets/${ticket.id}`" class="text-sm font-medium text-brand-600 hover:underline">
                    {{ $t('Back to tickets') }}
                </Link>
            </div>

            <form class="space-y-4 rounded-sm border border-border bg-surface p-4" @submit.prevent="submit">
                <InputField v-model="form.title" name="title" :title="$t('Title')" :errors="form.errors" required />
                <TextareaField v-model="form.message" name="message" :title="$t('Message')" :rows="6" :errors="form.errors" required />

                <div class="grid gap-4 sm:grid-cols-2">
                    <SelectField
                        v-model="form.priority"
                        name="priority"
                        :title="$t('Priority')"
                        :value-name-array="priorityOptions"
                        :errors="form.errors"
                        translate
                    />
                    <SelectField
                        v-model="form.status"
                        name="status"
                        :title="$t('Status')"
                        :value-name-array="statusOptions"
                        :errors="form.errors"
                        translate
                    />
                </div>

                <div v-if="categories.length" class="flex flex-col gap-1">
                    <span class="text-sm font-medium text-ink">{{ $t('Categories') }}</span>
                    <div class="flex flex-wrap gap-2">
                        <label
                            v-for="slug in categories"
                            :key="slug"
                            class="inline-flex cursor-pointer items-center gap-2 rounded-sm border px-3 py-1.5 text-sm transition"
                            :class="form.categories.includes(slug) ? 'border-brand-500 bg-brand-50 text-brand-800' : 'border-border text-ink-soft hover:bg-surface-accent'"
                        >
                            <input type="checkbox" class="sr-only" :checked="form.categories.includes(slug)" @change="toggle('categories', slug)" />
                            {{ $t(`tickets.category.${slug}`) }}
                        </label>
                    </div>
                </div>

                <div v-if="labels.length" class="flex flex-col gap-1">
                    <span class="text-sm font-medium text-ink">{{ $t('Labels') }}</span>
                    <div class="flex flex-wrap gap-2">
                        <label
                            v-for="slug in labels"
                            :key="slug"
                            class="inline-flex cursor-pointer items-center gap-2 rounded-sm border px-3 py-1.5 text-sm transition"
                            :class="form.labels.includes(slug) ? 'border-brand-500 bg-brand-50 text-brand-800' : 'border-border text-ink-soft hover:bg-surface-accent'"
                        >
                            <input type="checkbox" class="sr-only" :checked="form.labels.includes(slug)" @change="toggle('labels', slug)" />
                            {{ $t(`tickets.label.${slug}`) }}
                        </label>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button
                        type="submit"
                        class="rounded-sm bg-brand-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-800 disabled:opacity-60"
                        :disabled="form.processing"
                    >
                        {{ $t('Save changes') }}
                    </button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
