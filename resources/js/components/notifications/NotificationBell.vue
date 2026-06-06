<script setup>
/**
 * Notification bell + dropdown (phase 4.1).
 *
 * Polling, not realtime (decision 1, shared-hosting without daemons):
 *  - the unread badge is refreshed on a light interval that only fires while the
 *    tab is visible (visibilitychange-aware), so a backgrounded tab is silent;
 *  - the recent list is fetched ON OPEN, not continuously.
 *
 * Uses fetch + the XSRF cookie (the core ships no axios), exactly like
 * QrLoginPanel. Mutations are scoped server-side to the caller's own
 * notifications, so this only reflects what the backend returns.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import NotificationItem from './NotificationItem.vue';

const props = defineProps({
    // Unread-count poll cadence. 60s keeps it cheap; the user sees new items
    // immediately when they open the dropdown regardless.
    pollIntervalMs: { type: Number, default: 60000 },
});

const open = ref(false);
const loading = ref(false);
const items = ref([]);
const unreadCount = ref(0);
let interval = null;

const badge = (count) => (count > 9 ? '9+' : String(count));

function xsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function getJson(name) {
    const response = await fetch(route(name), {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(`Request failed: ${response.status}`);
    }

    return response.json();
}

async function postJson(name, param) {
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

async function refreshCount() {
    try {
        const data = await getJson('notifications.unread-count');
        unreadCount.value = data.unread_count ?? 0;
    } catch {
        // Transient poll errors are ignored; the next tick retries.
    }
}

async function loadRecent() {
    loading.value = true;

    try {
        const data = await getJson('notifications.recent');
        items.value = data.notifications?.data ?? data.notifications ?? [];
        unreadCount.value = data.unread_count ?? 0;
    } catch {
        items.value = [];
    } finally {
        loading.value = false;
    }
}

async function toggle() {
    open.value = !open.value;

    if (open.value) {
        await loadRecent();
    }
}

async function markRead(id) {
    try {
        const data = await postJson('notifications.read', id);
        const item = items.value.find((notification) => notification.id === id);
        if (item) {
            item.read_at = new Date().toISOString();
        }
        unreadCount.value = data.unread_count ?? Math.max(0, unreadCount.value - 1);
    } catch {
        // Ignore; the badge re-syncs on the next poll.
    }
}

async function markAll() {
    try {
        await postJson('notifications.read-all');
        items.value.forEach((notification) => {
            notification.read_at = notification.read_at ?? new Date().toISOString();
        });
        unreadCount.value = 0;
    } catch {
        // Ignore; the badge re-syncs on the next poll.
    }
}

function onVisibilityChange() {
    if (document.visibilityState === 'visible') {
        refreshCount();
    }
}

onMounted(() => {
    refreshCount();
    interval = setInterval(() => {
        if (document.visibilityState === 'visible') {
            refreshCount();
        }
    }, props.pollIntervalMs);
    document.addEventListener('visibilitychange', onVisibilityChange);
});

onBeforeUnmount(() => {
    if (interval) {
        clearInterval(interval);
        interval = null;
    }
    document.removeEventListener('visibilitychange', onVisibilityChange);
});
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="relative inline-flex h-9 w-9 items-center justify-center rounded-sm text-ink-soft transition hover:bg-surface-accent hover:text-ink"
            :aria-label="$t('Notifications')"
            @click="toggle"
        >
            <svg
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
                class="h-5 w-5"
                aria-hidden="true"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"
                />
            </svg>

            <span
                v-if="unreadCount > 0"
                class="absolute -right-0.5 -top-0.5 inline-flex min-w-4 items-center justify-center rounded-full bg-brand-600 px-1 text-[10px] font-semibold leading-4 text-white"
            >
                {{ badge(unreadCount) }}
            </span>
        </button>

        <div
            v-if="open"
            class="absolute right-0 z-20 mt-2 w-80 overflow-hidden rounded-md border border-border bg-surface shadow-lg"
        >
            <div class="flex items-center justify-between border-b border-border px-3 py-2">
                <span class="text-sm font-semibold text-ink">{{ $t('Notifications') }}</span>
                <button
                    v-if="unreadCount > 0"
                    type="button"
                    class="text-xs font-medium text-brand-600 hover:underline"
                    @click="markAll"
                >
                    {{ $t('Mark all read') }}
                </button>
            </div>

            <div class="max-h-96 divide-y divide-border overflow-y-auto">
                <p v-if="loading" class="px-3 py-6 text-center text-sm text-ink-soft">
                    {{ $t('Loading…') }}
                </p>
                <p v-else-if="items.length === 0" class="px-3 py-6 text-center text-sm text-ink-soft">
                    {{ $t('No notifications yet') }}
                </p>
                <NotificationItem
                    v-for="notification in items"
                    v-else
                    :key="notification.id"
                    :notification="notification"
                    @read="markRead"
                />
            </div>

            <div class="border-t border-border px-3 py-2 text-center">
                <Link href="/notifications" class="text-sm font-medium text-brand-600 hover:underline">
                    {{ $t('View all notifications') }}
                </Link>
            </div>
        </div>
    </div>
</template>
