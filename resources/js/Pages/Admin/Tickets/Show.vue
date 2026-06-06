<script setup>
/**
 * Operator view of one ticket (phase 4.2).
 *
 * Shows the conversation and the operator controls: toggle category/label chips,
 * set priority, reply (→ wait-customer + notifies the user) and close. Each
 * mutation posts to its own endpoint and preserves scroll; the backend audits
 * the change. Message bodies render as text (TicketMessageList), never v-html.
 */
import { computed } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { AdminLayout, TextareaField, TicketMessageList, TicketStatusBadge } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    ticket: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    labels: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
});

const base = computed(() => `/admin/tickets/${props.ticket.id}`);
const ticketCategories = computed(() => props.ticket.categories ?? []);
const ticketLabels = computed(() => props.ticket.labels ?? []);
const isResolved = computed(() => props.ticket.status === 'resolved');

const form = useForm({ message: '' });

function reply() {
    form.post(`${base.value}/reply`, {
        preserveScroll: true,
        onSuccess: () => form.reset('message'),
    });
}

function toggleCategory(slug) {
    router.post(`${base.value}/category`, { slug }, { preserveScroll: true });
}

function toggleLabel(slug) {
    router.post(`${base.value}/label`, { slug }, { preserveScroll: true });
}

function setPriority(priority) {
    router.patch(`${base.value}/priority`, { priority }, { preserveScroll: true });
}

function close() {
    router.patch(`${base.value}/close`, {}, { preserveScroll: true });
}
</script>

<template>
    <AdminLayout :title="$t('Support')">
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <div class="flex items-center justify-between">
                    <Link href="/admin/tickets" class="text-sm font-medium text-brand-600 hover:underline">
                        {{ $t('Back to tickets') }}
                    </Link>
                    <Link :href="`${base}/edit`" class="text-sm font-medium text-brand-600 hover:underline">
                        {{ $t('Edit') }}
                    </Link>
                </div>

                <div class="rounded-sm border border-border bg-surface p-4">
                    <div class="flex items-start justify-between gap-4">
                        <h1 class="text-lg font-semibold text-ink">{{ ticket.title }}</h1>
                        <TicketStatusBadge :status="ticket.status" />
                    </div>
                    <p class="mt-1 text-xs text-ink-soft">
                        {{ ticket.user?.name }} · {{ ticket.user?.email }} · {{ ticket.created_human }}
                    </p>
                    <p class="mt-3 whitespace-pre-line text-sm text-ink">{{ ticket.message }}</p>
                </div>

                <TicketMessageList :messages="ticket.messages ?? []" />

                <form class="space-y-3 rounded-sm border border-border bg-surface p-4" @submit.prevent="reply">
                    <TextareaField
                        v-model="form.message"
                        name="message"
                        :title="$t('Reply')"
                        :rows="4"
                        :errors="form.errors"
                        required
                    />
                    <div class="flex justify-end">
                        <button
                            type="submit"
                            class="rounded-sm bg-brand-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-800 disabled:opacity-60"
                            :disabled="form.processing"
                        >
                            {{ $t('Send reply') }}
                        </button>
                    </div>
                </form>
            </div>

            <aside class="space-y-4">
                <div class="rounded-sm border border-border bg-surface p-4">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-soft">{{ $t('Priority') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="slug in priorities"
                            :key="slug"
                            type="button"
                            :aria-pressed="ticket.priority === slug"
                            class="rounded-sm border px-3 py-1.5 text-sm transition"
                            :class="ticket.priority === slug ? 'border-brand-500 bg-brand-50 text-brand-800' : 'border-border text-ink-soft hover:bg-surface-accent'"
                            @click="setPriority(slug)"
                        >
                            {{ $t(`tickets.priority.${slug}`) }}
                        </button>
                    </div>
                </div>

                <div v-if="categories.length" class="rounded-sm border border-border bg-surface p-4">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-soft">{{ $t('Categories') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="slug in categories"
                            :key="slug"
                            type="button"
                            :aria-pressed="ticketCategories.includes(slug)"
                            class="rounded-full border px-2.5 py-1 text-xs transition"
                            :class="ticketCategories.includes(slug) ? 'border-brand-500 bg-brand-50 text-brand-800' : 'border-border text-ink-soft hover:bg-surface-accent'"
                            @click="toggleCategory(slug)"
                        >
                            {{ $t(`tickets.category.${slug}`) }}
                        </button>
                    </div>
                </div>

                <div v-if="labels.length" class="rounded-sm border border-border bg-surface p-4">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-soft">{{ $t('Labels') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="slug in labels"
                            :key="slug"
                            type="button"
                            :aria-pressed="ticketLabels.includes(slug)"
                            class="rounded-full border px-2.5 py-1 text-xs transition"
                            :class="ticketLabels.includes(slug) ? 'border-brand-500 bg-brand-50 text-brand-800' : 'border-border text-ink-soft hover:bg-surface-accent'"
                            @click="toggleLabel(slug)"
                        >
                            {{ $t(`tickets.label.${slug}`) }}
                        </button>
                    </div>
                </div>

                <button
                    v-if="!isResolved"
                    type="button"
                    class="w-full rounded-sm border border-emerald-300 bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-800 transition hover:bg-emerald-100"
                    @click="close"
                >
                    {{ $t('Close ticket') }}
                </button>
            </aside>
        </div>
    </AdminLayout>
</template>
