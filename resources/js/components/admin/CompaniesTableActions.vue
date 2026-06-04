<script setup>
/**
 * Per-row action buttons for the super-admin company table (phase 3.3).
 *
 * Pure UI: shows view plus the right block/unblock action for the row's state
 * and emits intent to the parent, which performs the request. The server policy
 * (super-admin only) is the real boundary — this just keeps the UI honest.
 */
defineProps({
    company: { type: Object, required: true },
});

defineEmits(['view', 'block', 'unblock']);
</script>

<template>
    <div class="flex items-center justify-end gap-2 text-xs">
        <button type="button" class="text-ink-soft transition hover:text-ink" @click="$emit('view')">
            {{ $t('View') }}
        </button>

        <button
            v-if="!company.is_blocked"
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
    </div>
</template>
