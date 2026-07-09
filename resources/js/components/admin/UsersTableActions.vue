<script setup>
/**
 * Per-row action buttons for the super-admin user table (phase 2.3, extended 3a).
 *
 * Pure UI: it shows the right actions for the row's state (block vs unblock,
 * delete vs restore, verify vs unverify) and emits intent to the parent, which
 * performs the request. The "Follow" (impersonate) action is offered only for an
 * active, non-admin user — the server policy is the real boundary (an admin
 * cannot be impersonated), this just keeps the UI honest.
 *
 * Phase 3a adds operator overrides — verify/unverify email (always) and phone
 * (only when the `phone` column is opted in) — plus quick links to the user's
 * activity log and to opening a support ticket on their behalf.
 */
defineProps({
    user: { type: Object, required: true },
    // The opt-in columns switched on for this table; gates the phone actions.
    userColumns: { type: Array, default: () => [] },
});

defineEmits([
    'edit',
    'block',
    'unblock',
    'delete',
    'restore',
    'impersonate',
    'verify-email',
    'unverify-email',
    'verify-phone',
    'unverify-phone',
    'logs',
    'ticket',
]);
</script>

<template>
    <div class="flex flex-wrap items-center justify-end gap-2 text-xs">
        <button type="button" class="text-ink-soft transition hover:text-ink" @click="$emit('edit')">
            {{ $t('Edit') }}
        </button>

        <button
            v-if="!user.email_verified"
            type="button"
            class="text-ink-soft transition hover:text-emerald-700"
            @click="$emit('verify-email')"
        >
            {{ $t('Verify email') }}
        </button>
        <button
            v-else
            type="button"
            class="text-ink-soft transition hover:text-amber-700"
            @click="$emit('unverify-email')"
        >
            {{ $t('Unverify email') }}
        </button>

        <template v-if="userColumns.includes('phone')">
            <button
                v-if="!user.phone_verified"
                type="button"
                class="text-ink-soft transition hover:text-emerald-700"
                @click="$emit('verify-phone')"
            >
                {{ $t('Verify phone') }}
            </button>
            <button
                v-else
                type="button"
                class="text-ink-soft transition hover:text-amber-700"
                @click="$emit('unverify-phone')"
            >
                {{ $t('Unverify phone') }}
            </button>
        </template>

        <button type="button" class="text-ink-soft transition hover:text-ink" @click="$emit('logs')">
            {{ $t('Logs') }}
        </button>

        <button type="button" class="text-ink-soft transition hover:text-ink" @click="$emit('ticket')">
            {{ $t('Create ticket') }}
        </button>

        <button
            v-if="!user.is_admin && !user.is_blocked && !user.is_deleted"
            type="button"
            class="text-ink-soft transition hover:text-brand-700"
            @click="$emit('impersonate')"
        >
            {{ $t('Follow') }}
        </button>

        <button
            v-if="!user.is_blocked"
            type="button"
            class="text-amber-700 transition hover:text-amber-800"
            @click="$emit('block')"
        >
            {{ $t('Block') }}
        </button>
        <button
            v-else
            type="button"
            class="text-emerald-700 transition hover:text-emerald-800"
            @click="$emit('unblock')"
        >
            {{ $t('Unblock') }}
        </button>

        <button
            v-if="!user.is_deleted"
            type="button"
            class="text-rose-700 transition hover:text-rose-800"
            @click="$emit('delete')"
        >
            {{ $t('Delete') }}
        </button>
        <button
            v-else
            type="button"
            class="text-emerald-700 transition hover:text-emerald-800"
            @click="$emit('restore')"
        >
            {{ $t('Restore') }}
        </button>
    </div>
</template>
