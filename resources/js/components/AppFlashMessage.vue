<script setup>
import { usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * Toast notifications driven by Inertia flash props.
 *
 * Consumes the core flash contract shared by `HandleInertiaRequests`:
 * `info` / `error` (persist until dismissed) and `disappear_info` /
 * `disappear_error` (auto-dismiss after a short delay). Place once in the
 * root layout. Tailwind-styled against the core theme.
 */
const page = usePage();

const messages = ref([]);

// Pending auto-dismiss timers, keyed by message `id` (slot + content). Keying
// on the content-derived id — not the bare slot — means a replacement message
// gets its own timer; a stale timer can never close the message that took its
// slot. Cleared in buildMessages() for messages that no longer exist.
const timeouts = {};

const AUTO_DISMISS_MS = 2500;

// Maps a message slot to its source key in the flash payload.
const FLASH_KEY = {
    info: 'info',
    error: 'error',
    'disappear-info': 'disappear_info',
    'disappear-error': 'disappear_error',
};

const clearTimer = (id) => {
    if (timeouts[id]) {
        clearTimeout(timeouts[id]);
        delete timeouts[id];
    }
};

const buildMessages = () => {
    const flash = page.props.flash ?? {};

    const next = [
        { slot: 'info', content: flash.info, type: 'info' },
        { slot: 'error', content: flash.error, type: 'error' },
        { slot: 'disappear-info', content: flash.disappear_info, type: 'disappear-info' },
        { slot: 'disappear-error', content: flash.disappear_error, type: 'disappear-error' },
    ]
        .filter((m) => m.content)
        // id ties the timer to this exact message, so a new message in the same
        // slot can't inherit the previous one's pending timer.
        .map((m) => ({ ...m, id: `${m.slot}:${m.content}` }));

    // Drop timers whose message is gone (dismissed or replaced).
    const live = new Set(next.map((m) => m.id));
    Object.keys(timeouts).forEach((id) => {
        if (!live.has(id)) {
            clearTimer(id);
        }
    });

    messages.value = next;
};

const closeMessage = (message) => {
    const flashKey = FLASH_KEY[message.slot];

    if (page.props.flash && flashKey) {
        page.props.flash[flashKey] = null;
    }

    messages.value = messages.value.filter((m) => m.id !== message.id);

    clearTimer(message.id);
};

const isDisappearType = (type) => type === 'disappear-error' || type === 'disappear-info';
const showCloseBtn = (type) => !isDisappearType(type);

const toneClass = (type) => {
    switch (type) {
        case 'error':
        case 'disappear-error':
            return 'border-danger bg-danger-50 text-danger-700';
        default:
            return 'border-brand-200 bg-brand-50 text-brand-900';
    }
};

watch(
    () => page.props.flash,
    () => {
        buildMessages();

        messages.value.forEach((m) => {
            if (isDisappearType(m.type) && !timeouts[m.id]) {
                timeouts[m.id] = setTimeout(() => closeMessage(m), AUTO_DISMISS_MS);
            }
        });
    },
    { deep: true, immediate: true },
);

// Don't leak pending timers when the layout unmounts.
onBeforeUnmount(() => {
    Object.keys(timeouts).forEach(clearTimer);
});
</script>

<template>
    <div class="pointer-events-none fixed top-4 right-4 z-50 flex w-full max-w-sm flex-col gap-2">
        <TransitionGroup name="flash" tag="div" class="flex flex-col gap-2">
            <div
                v-for="m in messages"
                :key="m.id"
                class="pointer-events-auto flex items-start gap-3 rounded-md border px-4 py-3 shadow-md"
                :class="[toneClass(m.type), isDisappearType(m.type) ? 'cursor-pointer' : '']"
                @click="isDisappearType(m.type) && closeMessage(m)"
            >
                <p class="flex-1 text-sm">{{ m.content }}</p>

                <button
                    v-if="showCloseBtn(m.type)"
                    type="button"
                    class="shrink-0 leading-none opacity-60 transition hover:opacity-100"
                    aria-label="Close"
                    @click.stop="closeMessage(m)"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 26 26" fill="none">
                        <path
                            fill-rule="evenodd"
                            clip-rule="evenodd"
                            d="M1.00298 22.5743C0.333669 23.2438 0.333772 24.3288 1.00319 24.9981C1.67261 25.6673 2.75784 25.6673 3.42714 24.9979L13.0002 15.4239L22.5741 24.997C23.2434 25.6663 24.3287 25.6663 24.998 24.997C25.6673 24.3278 25.6673 23.2426 24.998 22.5733L15.424 13L24.9973 3.42565C25.6665 2.75629 25.6665 1.67113 24.997 1.00189C24.3277 0.33262 23.2424 0.332722 22.5731 1.00209L12.9999 10.5762L3.42597 1.00291C2.75662 0.333613 1.67137 0.333613 1.00203 1.00291C0.332658 1.67223 0.332658 2.75738 1.00203 3.4267L10.5763 13.0001L1.00298 22.5743Z"
                            fill="currentColor"
                        />
                    </svg>
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>

<style scoped>
.flash-enter-active,
.flash-leave-active {
    transition: all 0.4s ease;
}
.flash-enter-from,
.flash-leave-to {
    opacity: 0;
    transform: translateX(1rem);
}
</style>
