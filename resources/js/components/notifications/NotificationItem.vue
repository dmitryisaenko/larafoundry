<script setup>
/**
 * One notification row in the bell dropdown or the inbox (phase 4.1).
 *
 * Title and body are rendered as TEXT via interpolation, never `v-html`
 * (security finding #1): the backend delivers them pre-localised and plain, so a
 * crafted broadcast body cannot inject markup. Actions are internal GET links
 * only — the backend already stripped anything else (finding #5), so an action
 * is a plain Inertia `<Link>` and can never POST or leave the origin.
 *
 * Emits `read` with the notification id when the user marks it read.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    notification: { type: Object, required: true },
});

defineEmits(['read']);

const unread = computed(() => !props.notification.read_at);

// Visual accent by type; an unknown/system type falls back to the neutral dot.
const DOT = {
    info: 'bg-brand-500',
    success: 'bg-emerald-500',
    warning: 'bg-amber-500',
    system: 'bg-ink-soft',
};

const dotClass = computed(() => DOT[props.notification.type] ?? 'bg-ink-soft');
</script>

<template>
    <div
        class="flex gap-3 rounded-sm px-3 py-2.5 transition"
        :class="unread ? 'bg-surface-accent' : 'bg-surface'"
    >
        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="dotClass" aria-hidden="true" />

        <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-ink">{{ notification.title }}</p>

            <p v-if="notification.body" class="mt-0.5 whitespace-pre-line text-sm text-ink-soft">
                {{ notification.body }}
            </p>

            <div v-if="notification.actions?.length" class="mt-2 flex flex-wrap gap-3">
                <Link
                    v-for="(action, index) in notification.actions"
                    :key="index"
                    :href="action.url"
                    class="text-xs font-medium text-brand-600 hover:underline"
                >
                    {{ action.label }}
                </Link>
            </div>

            <p class="mt-1 text-xs text-ink-soft">{{ notification.created_human }}</p>
        </div>

        <button
            v-if="unread"
            type="button"
            class="self-start text-xs font-medium text-brand-600 hover:underline"
            @click="$emit('read', notification.id)"
        >
            {{ $t('Mark read') }}
        </button>
    </div>
</template>
