<script setup>
/**
 * The user's own support tickets (phase 4.2).
 *
 * Every authenticated user reaches this from the header Support link. The
 * backend scopes the list to the caller, hides long-resolved tickets and orders
 * them by the support workflow, so this page just renders what it is given.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { AppBaseLayout, PagePaginator, TicketStatusBadge, TicketPriorityBadge } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    tickets: { type: Object, default: () => ({ data: [] }) },
});

const rows = computed(() => props.tickets.data ?? []);
</script>

<template>
    <AppBaseLayout>
        <div class="mx-auto w-full max-w-3xl space-y-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold text-ink">{{ $t('Support center') }}</h1>
                <Link
                    href="/tickets/create"
                    class="rounded-sm bg-brand-700 px-3 py-2 text-sm font-medium text-white transition hover:bg-brand-800"
                >
                    {{ $t('Create ticket') }}
                </Link>
            </div>

            <div class="divide-y divide-border rounded-sm border border-border bg-surface">
                <Link
                    v-for="ticket in rows"
                    :key="ticket.id"
                    :href="`/tickets/${ticket.uuid}`"
                    class="flex items-center justify-between gap-4 px-4 py-3 transition hover:bg-surface-accent"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium text-ink">{{ ticket.title }}</p>
                        <p class="mt-0.5 text-xs text-ink-soft">
                            {{ $t('Updated') }} {{ ticket.updated_human }}
                            · {{ ticket.messages_count ?? 0 }} {{ $t('Messages') }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <TicketPriorityBadge :priority="ticket.priority" />
                        <TicketStatusBadge :status="ticket.status" />
                    </div>
                </Link>

                <p v-if="rows.length === 0" class="px-4 py-10 text-center text-sm text-ink-soft">
                    {{ $t('No tickets yet') }}
                </p>
            </div>

            <PagePaginator />
        </div>
    </AppBaseLayout>
</template>
