<script setup>
/**
 * A ticket conversation thread (phase 4.2).
 *
 * Every message body is rendered as TEXT via interpolation (whitespace-pre-line),
 * never `v-html` — closing the donor's XSS hole (security finding S1). The
 * `is_agent` flag from the backend resource places operator messages on one side
 * and the customer's on the other; the side labels are i18n keys.
 */
defineProps({
    messages: { type: Array, default: () => [] },
});
</script>

<template>
    <ol class="space-y-3">
        <li
            v-for="message in messages"
            :key="message.id"
            class="flex"
            :class="message.is_agent ? 'justify-start' : 'justify-end'"
        >
            <div
                class="max-w-[80%] rounded-md border px-3 py-2"
                :class="message.is_agent
                    ? 'border-border bg-surface'
                    : 'border-brand-200 bg-brand-50'"
            >
                <div class="mb-1 flex items-center justify-between gap-3 text-xs text-ink-soft">
                    <span class="font-medium text-ink">
                        {{ message.is_agent ? $t('Support team') : (message.author?.name ?? $t('You')) }}
                    </span>
                    <span>{{ message.created_human }}</span>
                </div>
                <p class="whitespace-pre-line text-sm text-ink">{{ message.message }}</p>
            </div>
        </li>
    </ol>
</template>
