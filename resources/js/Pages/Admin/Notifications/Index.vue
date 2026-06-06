<script setup>
/**
 * Super-admin broadcast list (phase 4.1). Super-admin only (gated server-side
 * by `larafoundry.admin`).
 *
 * Drafts can be edited and sent; sending is a server-side queued fan-out, so the
 * row just posts to the send endpoint. The server is the authority for every
 * action.
 */
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { AdminLayout, PagePaginator } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    notifications: { type: Object, default: () => ({ data: [] }) },
});

const rows = computed(() => props.notifications.data ?? []);

const STATUS_CLASS = {
    draft: 'bg-surface-accent text-ink-soft',
    sending: 'bg-amber-100 text-amber-800',
    sent: 'bg-emerald-100 text-emerald-800',
};

function statusClass(status) {
    return STATUS_CLASS[status] ?? 'bg-surface-accent text-ink-soft';
}

function create() {
    router.get('/admin/notifications/create');
}

function edit(notification) {
    router.get(`/admin/notifications/${notification.id}/edit`);
}

function send(notification) {
    router.post(`/admin/notifications/${notification.id}/send`, {}, { preserveScroll: true });
}

function destroy(notification) {
    router.delete(`/admin/notifications/${notification.id}`, { preserveScroll: true });
}
</script>

<template>
    <AdminLayout :title="$t('Broadcasts')">
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <h1 class="text-lg font-semibold text-ink">{{ $t('Broadcasts') }}</h1>
                <button
                    type="button"
                    class="rounded-sm bg-brand-700 px-3 py-2 text-sm font-medium text-white transition hover:bg-brand-800"
                    @click="create"
                >
                    {{ $t('New broadcast') }}
                </button>
            </div>

            <div class="overflow-x-auto rounded-sm border border-border bg-surface">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs uppercase tracking-wide text-ink-soft">
                        <tr>
                            <th class="px-3 py-2">{{ $t('Title') }}</th>
                            <th class="px-3 py-2">{{ $t('Status') }}</th>
                            <th class="px-3 py-2">{{ $t('Recipients') }}</th>
                            <th class="px-3 py-2">{{ $t('Read') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="notification in rows" :key="notification.id">
                            <td class="px-3 py-2 text-ink">{{ notification.title }}</td>
                            <td class="px-3 py-2">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="statusClass(notification.status)"
                                >
                                    {{ $t(notification.status) }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-ink-soft">{{ notification.total_recipients ?? 0 }}</td>
                            <td class="px-3 py-2 text-ink-soft">{{ notification.read_count ?? 0 }}</td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-2">
                                    <button
                                        v-if="notification.status === 'draft'"
                                        type="button"
                                        class="text-xs font-medium text-brand-600 hover:underline"
                                        @click="edit(notification)"
                                    >
                                        {{ $t('Edit') }}
                                    </button>
                                    <button
                                        v-if="notification.status === 'draft'"
                                        type="button"
                                        class="text-xs font-medium text-emerald-600 hover:underline"
                                        @click="send(notification)"
                                    >
                                        {{ $t('Send') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="text-xs font-medium text-danger hover:underline"
                                        @click="destroy(notification)"
                                    >
                                        {{ $t('Delete') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="rows.length === 0">
                            <td colspan="5" class="px-3 py-6 text-center text-ink-soft">
                                {{ $t('No broadcasts yet') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <PagePaginator />
        </div>
    </AdminLayout>
</template>
