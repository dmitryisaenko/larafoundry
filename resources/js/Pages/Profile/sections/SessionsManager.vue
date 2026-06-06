<script setup>
/**
 * Active sessions list with per-device revoke (phase 5.1).
 *
 * Lists the user's tracked sessions (device / browser / IP / last activity),
 * marks the current one, and revokes a chosen device or every other device.
 * Revoking the current device is intentionally not offered — that is "log out".
 */
import { router } from '@inertiajs/vue3';

defineProps({
    sessions: { type: Array, default: () => [] },
});

function revoke(id) {
    router.delete(`/auth/sessions/${id}`, { preserveScroll: true });
}

function revokeOthers() {
    router.delete('/auth/sessions/others', { preserveScroll: true });
}

function formatDate(value) {
    if (!value) {
        return '';
    }
    return new Date(value).toLocaleString();
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
                v-if="sessions.length > 1"
                type="button"
                class="shrink-0 rounded-sm border border-border px-3 py-2 text-sm transition hover:bg-surface-soft"
                @click="revokeOthers"
            >
                {{ $t('Sign out other devices') }}
            </button>
        </header>

        <ul class="flex flex-col gap-2">
            <li
                v-for="session in sessions"
                :key="session.id"
                class="flex items-center justify-between gap-3 rounded-sm border border-border bg-surface p-3"
            >
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-ink">
                        {{ session.browser || $t('Unknown browser') }} · {{ session.os || $t('Unknown OS') }}
                        <span v-if="session.is_current" class="ml-1 text-xs font-normal text-success">
                            ({{ $t('this device') }})
                        </span>
                    </p>
                    <p class="truncate text-xs text-ink-soft">
                        {{ session.device_name || session.device_type || $t('Unknown device') }}
                        · {{ session.ip_address }} · {{ formatDate(session.last_activity) }}
                    </p>
                    <p v-if="session.login_method && session.login_method !== 'native'" class="text-xs text-warning">
                        {{ $t('Signed in through a third-party provider.') }}
                    </p>
                </div>

                <button
                    v-if="!session.is_current"
                    type="button"
                    class="shrink-0 rounded-sm border border-danger px-3 py-1.5 text-xs text-danger transition hover:bg-danger-50"
                    @click="revoke(session.id)"
                >
                    {{ $t('Sign out') }}
                </button>
            </li>
        </ul>
    </section>
</template>
