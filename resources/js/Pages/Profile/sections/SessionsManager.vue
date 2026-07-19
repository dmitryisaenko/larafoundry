<script setup>
/**
 * Active sessions list with per-device revoke (phase 5.1).
 *
 * Lists the user's tracked devices and revokes a chosen one or every other
 * device. Two kinds of row share the list, discriminated by `item.type`:
 *  - 'session' (or unset, for the admin operator hub's older payload) — a web
 *    browser session: parsed user_agent, "this device" for the current one,
 *    revoke via `endpoint`;
 *  - 'token' — an API-token device (mobile app / other API client): the
 *    host-provided label, revoke via `tokenEndpoint`.
 * Revoking the current device is intentionally not offered — that is "log out".
 */
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useDateFormat } from '../../../composables/useDateFormat.js';

const props = defineProps({
    sessions: { type: Array, default: () => [] },
    // Base path for web-session revoke actions: `${endpoint}/{id}` and
    // `${endpoint}/others`. Defaults to the tenant endpoint; the operator hub
    // points it at its admin-namespaced clone.
    endpoint: { type: String, default: '/auth/sessions' },
    // Base path for token-device revoke: `${tokenEndpoint}/{id}`.
    tokenEndpoint: { type: String, default: '/auth/tokens' },
});

// Honours the user's date_format preference (auto/dmy/mdy/iso) + app locale.
const { formatDateTime } = useDateFormat();

const isToken = (item) => item.type === 'token';

// "Sign out other devices" only clears web sessions, so gate it on the web-session
// count — not the combined list (a lone browser + a phone token must not offer it).
const webSessionCount = computed(() => props.sessions.filter((item) => ! isToken(item)).length);

function revoke(id) {
    router.delete(`${props.endpoint}/${id}`, { preserveScroll: true });
}

function revokeToken(id) {
    router.delete(`${props.tokenEndpoint}/${id}`, { preserveScroll: true });
}

function revokeOthers() {
    router.delete(`${props.endpoint}/others`, { preserveScroll: true });
}
</script>

<template>
    <section class="flex max-w-2xl flex-col gap-4">
        <header class="flex items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-ink">{{ $t('Active sessions') }}</h2>
                <p class="text-sm text-ink-soft">{{ $t('Devices currently signed in to your account.') }}</p>
            </div>
            <button
                v-if="webSessionCount > 1"
                type="button"
                class="shrink-0 rounded-sm border border-border px-3 py-2 text-sm transition hover:bg-surface-soft"
                @click="revokeOthers"
            >
                {{ $t('Sign out other devices') }}
            </button>
        </header>

        <ul class="flex flex-col gap-2">
            <li
                v-for="item in sessions"
                :key="`${item.type || 'session'}-${item.id}`"
                class="flex items-center justify-between gap-3 rounded-sm border border-border bg-surface p-3"
            >
                <!-- Token device (mobile app / other API client). -->
                <template v-if="isToken(item)">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-ink">
                            {{ item.label || $t('Mobile device') }}
                        </p>
                        <p class="truncate text-xs text-ink-soft">
                            {{ $t('Last active') }} {{ formatDateTime(item.last_activity) }}
                        </p>
                    </div>

                    <button
                        type="button"
                        class="shrink-0 rounded-sm border border-danger px-3 py-1.5 text-xs text-danger transition hover:bg-danger-50"
                        @click="revokeToken(item.id)"
                    >
                        {{ $t('Log out this device') }}
                    </button>
                </template>

                <!-- Web browser session. -->
                <template v-else>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-ink">
                            {{ item.browser || $t('Unknown browser') }} · {{ item.os || $t('Unknown OS') }}
                            <span v-if="item.is_current" class="ml-1 text-xs font-normal text-success">
                                ({{ $t('this device') }})
                            </span>
                        </p>
                        <p class="truncate text-xs text-ink-soft">
                            {{ item.device_name || item.device_type || $t('Unknown device') }}
                            · {{ item.ip_address }} · {{ formatDateTime(item.last_activity) }}
                        </p>
                        <p v-if="item.login_method && item.login_method !== 'native'" class="text-xs text-warning">
                            {{ $t('Signed in through a third-party provider.') }}
                        </p>
                    </div>

                    <button
                        v-if="!item.is_current"
                        type="button"
                        class="shrink-0 rounded-sm border border-danger px-3 py-1.5 text-xs text-danger transition hover:bg-danger-50"
                        @click="revoke(item.id)"
                    >
                        {{ $t('Sign out') }}
                    </button>
                </template>
            </li>
        </ul>
    </section>
</template>
