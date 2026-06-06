<script setup>
/**
 * One of the user's own tickets with its conversation (phase 4.2).
 *
 * The opening message is shown first, then the reply thread (TicketMessageList,
 * which renders every body as text — never v-html, finding S1). The reply box
 * posts to the scoped endpoint; replying reopens a resolved ticket.
 */
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { AppBaseLayout, TextareaField, TicketMessageList, TicketStatusBadge, TicketPriorityBadge } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    ticket: { type: Object, required: true },
});

const categories = computed(() => props.ticket.categories ?? []);

const form = useForm({ message: '' });

function reply() {
    form.post(`/tickets/${props.ticket.uuid}/messages`, {
        preserveScroll: true,
        onSuccess: () => form.reset('message'),
    });
}
</script>

<template>
    <AppBaseLayout>
        <div class="mx-auto w-full max-w-2xl space-y-4">
            <Link href="/tickets" class="text-sm font-medium text-brand-600 hover:underline">
                {{ $t('Back to tickets') }}
            </Link>

            <div class="rounded-sm border border-border bg-surface p-4">
                <div class="flex items-start justify-between gap-4">
                    <h1 class="text-lg font-semibold text-ink">{{ ticket.title }}</h1>
                    <div class="flex shrink-0 items-center gap-2">
                        <TicketPriorityBadge :priority="ticket.priority" />
                        <TicketStatusBadge :status="ticket.status" />
                    </div>
                </div>

                <div v-if="categories.length" class="mt-2 flex flex-wrap gap-1.5">
                    <span
                        v-for="slug in categories"
                        :key="slug"
                        class="rounded-full bg-surface-accent px-2 py-0.5 text-xs text-ink-soft"
                    >
                        {{ $t(`tickets.category.${slug}`) }}
                    </span>
                </div>

                <p class="mt-3 whitespace-pre-line text-sm text-ink">{{ ticket.message }}</p>
                <p class="mt-1 text-xs text-ink-soft">{{ ticket.created_human }}</p>
            </div>

            <TicketMessageList :messages="ticket.messages ?? []" />

            <form class="space-y-3 rounded-sm border border-border bg-surface p-4" @submit.prevent="reply">
                <TextareaField
                    v-model="form.message"
                    name="message"
                    :title="$t('Your message')"
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
    </AppBaseLayout>
</template>
