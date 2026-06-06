<script setup>
/**
 * The recipient's notification inbox (phase 4.1).
 *
 * Lists the user's own notifications (the backend scopes every query to the
 * caller, so there is nothing cross-user here). Marking read posts to the
 * scoped endpoints with fetch + the XSRF cookie — the same axios-free idiom the
 * bell uses — and updates the row in place; "unread" is derived from the rows,
 * so the header button hides once everything is read.
 */
import { computed } from 'vue';
import { AppBaseLayout, NotificationItem, PagePaginator } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    notifications: { type: Object, default: () => ({ data: [] }) },
});

const rows = computed(() => props.notifications.data ?? []);
const hasUnread = computed(() => rows.value.some((notification) => !notification.read_at));

function xsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function post(name, param) {
    const response = await fetch(route(name, param), {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(`Request failed: ${response.status}`);
    }

    return response.json();
}

async function markRead(id) {
    const item = rows.value.find((notification) => notification.id === id);
    if (!item || item.read_at) {
        return;
    }

    try {
        await post('notifications.read', id);
        item.read_at = new Date().toISOString();
    } catch {
        // Ignore; a reload re-syncs the read state.
    }
}

async function markAll() {
    try {
        await post('notifications.read-all');
        rows.value.forEach((notification) => {
            notification.read_at = notification.read_at ?? new Date().toISOString();
        });
    } catch {
        // Ignore; a reload re-syncs the read state.
    }
}
</script>

<template>
    <AppBaseLayout>
        <div class="mx-auto w-full max-w-2xl space-y-4 p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold text-ink">{{ $t('Notifications') }}</h1>
                <button
                    v-if="hasUnread"
                    type="button"
                    class="text-sm font-medium text-brand-600 hover:underline"
                    @click="markAll"
                >
                    {{ $t('Mark all read') }}
                </button>
            </div>

            <div class="divide-y divide-border rounded-sm border border-border bg-surface">
                <NotificationItem
                    v-for="notification in rows"
                    :key="notification.id"
                    :notification="notification"
                    @read="markRead"
                />
                <p v-if="rows.length === 0" class="px-3 py-10 text-center text-sm text-ink-soft">
                    {{ $t('No notifications yet') }}
                </p>
            </div>

            <PagePaginator />
        </div>
    </AppBaseLayout>
</template>
