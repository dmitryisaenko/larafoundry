<script setup>
/**
 * Operator ticket queue (phase 4.2). Super-admin only (gated server-side by
 * `larafoundry.admin`).
 *
 * Shows the queue counters, the filter bar and the workflow-ordered list. The
 * backend owns filtering/sorting; this page just submits the filter query and
 * renders rows.
 */
import { computed, reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { AdminLayout, PagePaginator, SelectField, TicketStatusBadge, TicketPriorityBadge } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    tickets: { type: Object, default: () => ({ data: [] }) },
    stats: { type: Object, default: () => ({ total: 0, open: 0, high_priority: 0, standard_priority: 0 }) },
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
});

const rows = computed(() => props.tickets.data ?? []);

const query = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    priority: props.filters.priority ?? '',
    show_tickets: props.filters.show_tickets ?? '',
});

const statusOptions = computed(() => props.statuses.map((s) => ({ value: s, name: `tickets.status.${s}` })));
const priorityOptions = computed(() => props.priorities.map((p) => ({ value: p, name: `tickets.priority.${p}` })));

function applyFilters() {
    const params = Object.fromEntries(Object.entries(query).filter(([, value]) => value !== '' && value !== false));

    router.get('/admin/tickets', params, { preserveState: true, replace: true });
}

function toggleResolved(event) {
    query.show_tickets = event.target.checked ? 'all' : '';
    applyFilters();
}
</script>

<template>
    <AdminLayout :title="$t('Support')">
        <div class="space-y-4">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-sm border border-border bg-surface p-3">
                    <p class="text-xs uppercase tracking-wide text-ink-soft">{{ $t('Total') }}</p>
                    <p class="text-2xl font-semibold text-ink">{{ stats.total }}</p>
                </div>
                <div class="rounded-sm border border-border bg-surface p-3">
                    <p class="text-xs uppercase tracking-wide text-ink-soft">{{ $t('Open') }}</p>
                    <p class="text-2xl font-semibold text-ink">{{ stats.open }}</p>
                </div>
                <div class="rounded-sm border border-border bg-surface p-3">
                    <p class="text-xs uppercase tracking-wide text-ink-soft">{{ $t('High priority') }}</p>
                    <p class="text-2xl font-semibold text-ink">{{ stats.high_priority }}</p>
                </div>
                <div class="rounded-sm border border-border bg-surface p-3">
                    <p class="text-xs uppercase tracking-wide text-ink-soft">{{ $t('tickets.priority.standard') }}</p>
                    <p class="text-2xl font-semibold text-ink">{{ stats.standard_priority }}</p>
                </div>
            </div>

            <form class="flex flex-wrap items-end gap-3 rounded-sm border border-border bg-surface p-3" @submit.prevent="applyFilters">
                <div class="min-w-48 flex-1">
                    <label class="text-sm font-medium text-ink" for="search">{{ $t('Search') }}</label>
                    <input
                        id="search"
                        v-model="query.search"
                        type="search"
                        class="mt-1 w-full rounded-sm border border-border bg-surface px-3 py-2 text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200"
                    />
                </div>
                <div class="w-40">
                    <SelectField
                        v-model="query.status"
                        name="status"
                        :title="$t('Status')"
                        :default-name="$t('All statuses')"
                        :value-name-array="statusOptions"
                        translate
                    />
                </div>
                <div class="w-40">
                    <SelectField
                        v-model="query.priority"
                        name="priority"
                        :title="$t('Priority')"
                        :default-name="$t('All priorities')"
                        :value-name-array="priorityOptions"
                        translate
                    />
                </div>
                <label class="inline-flex items-center gap-2 text-sm text-ink-soft">
                    <input type="checkbox" :checked="query.show_tickets === 'all'" @change="toggleResolved" />
                    {{ $t('Show resolved') }}
                </label>
                <button
                    type="submit"
                    class="rounded-sm bg-brand-700 px-3 py-2 text-sm font-medium text-white transition hover:bg-brand-800"
                >
                    {{ $t('Search') }}
                </button>
            </form>

            <div class="overflow-x-auto rounded-sm border border-border bg-surface">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs uppercase tracking-wide text-ink-soft">
                        <tr>
                            <th class="px-3 py-2">{{ $t('Title') }}</th>
                            <th class="px-3 py-2">{{ $t('Customer') }}</th>
                            <th class="px-3 py-2">{{ $t('Priority') }}</th>
                            <th class="px-3 py-2">{{ $t('Status') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="ticket in rows" :key="ticket.id">
                            <td class="px-3 py-2 text-ink">{{ ticket.title }}</td>
                            <td class="px-3 py-2 text-ink-soft">{{ ticket.user?.name ?? '—' }}</td>
                            <td class="px-3 py-2"><TicketPriorityBadge :priority="ticket.priority" /></td>
                            <td class="px-3 py-2"><TicketStatusBadge :status="ticket.status" /></td>
                            <td class="px-3 py-2 text-right">
                                <Link :href="`/admin/tickets/${ticket.id}`" class="text-xs font-medium text-brand-600 hover:underline">
                                    {{ $t('View') }}
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="rows.length === 0">
                            <td colspan="5" class="px-3 py-6 text-center text-ink-soft">{{ $t('No tickets yet') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <PagePaginator />
        </div>
    </AdminLayout>
</template>
