<script setup>
/**
 * Open a new support ticket (phase 4.2).
 *
 * Owns the Inertia form and submission; the shared TicketForm renders the common
 * fields (title / message / priority / categories). On success the controller
 * redirects to the new ticket's thread.
 */
import { Link, useForm } from '@inertiajs/vue3';
import { AppBaseLayout, TicketForm } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
});

const form = useForm({
    title: '',
    message: '',
    priority: props.priorities[0] ?? 'standard',
    categories: [],
});

function submit() {
    form.post('/tickets');
}
</script>

<template>
    <AppBaseLayout>
        <div class="mx-auto w-full max-w-2xl space-y-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold text-ink">{{ $t('Create ticket') }}</h1>
                <Link href="/tickets" class="text-sm font-medium text-brand-600 hover:underline">
                    {{ $t('Back to tickets') }}
                </Link>
            </div>

            <form class="space-y-4 rounded-sm border border-border bg-surface p-4" @submit.prevent="submit">
                <TicketForm :form="form" :categories="categories" :priorities="priorities" />

                <div class="flex justify-end">
                    <button
                        type="submit"
                        class="rounded-sm bg-brand-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-800 disabled:opacity-60"
                        :disabled="form.processing"
                    >
                        {{ $t('Send') }}
                    </button>
                </div>
            </form>
        </div>
    </AppBaseLayout>
</template>
