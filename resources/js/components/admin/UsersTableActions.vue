<script setup>
/**
 * Per-row action buttons for the super-admin user table (phase 2.3).
 *
 * Pure UI: it shows the right actions for the row's state (block vs unblock,
 * delete vs restore) and emits intent to the parent, which performs the request.
 * The "Follow" (impersonate) action is offered only for an active, non-admin
 * user — the server policy is the real boundary (an admin cannot be
 * impersonated), this just keeps the UI honest.
 */
defineProps({
    user: { type: Object, required: true },
});

defineEmits(['edit', 'block', 'unblock', 'delete', 'restore', 'impersonate']);
</script>

<template>
    <div class="flex items-center justify-end gap-2 text-xs">
        <button type="button" class="text-ink-soft transition hover:text-ink" @click="$emit('edit')">
            {{ $t('Edit') }}
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
