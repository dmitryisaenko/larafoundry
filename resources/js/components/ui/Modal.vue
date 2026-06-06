<script>
// Module-shared reference count of how many Modal instances currently hold the
// body scroll-lock. This lives in a plain <script> (true module scope) — a
// variable declared in <script setup> would be compiled into setup() and thus
// be per-instance, defeating the cross-modal coordination.
let scrollLockCount = 0;

export default { name: 'Modal' };
</script>

<script setup>
/**
 * Reusable overlay modal.
 *
 * A self-contained Teleport + Transition dialog: no external dialog library
 * (the core deliberately keeps its runtime dependency surface minimal — the
 * donor pulled in a Dialog primitive it never actually used). It owns nothing
 * but presentation: visibility is driven by the `open` prop and every dismissal
 * path (Esc, backdrop click, the close button) funnels through a single `close`
 * emit. The parent decides what closing means, so the donor's bugs are gone by
 * construction — there is no lingering overlay, no remount-key hack, and a
 * validation error never closes the modal because only an explicit parent
 * action flips `open`.
 *
 * Props:
 *  - open            {boolean} controls visibility (v-if on the overlay)
 *  - title           {string}  optional heading
 *  - closeOnEsc      {boolean} Escape emits close (default true)
 *  - closeOnBackdrop {boolean} backdrop click emits close (default true)
 *
 * Emits:
 *  - close  requested dismissal; parent owns the open state
 *
 * Slots: default (body), footer.
 */
import { onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    closeOnEsc: { type: Boolean, default: true },
    closeOnBackdrop: { type: Boolean, default: true },
});

const emit = defineEmits(['close']);

const panel = ref(null);

function requestClose() {
    emit('close');
}

function onBackdrop() {
    if (props.closeOnBackdrop) {
        requestClose();
    }
}

function onKeydown(event) {
    if (event.key === 'Escape' && props.closeOnEsc) {
        event.preventDefault();
        requestClose();
        return;
    }

    // Minimal focus trap: keep Tab within the panel so keyboard users can't
    // wander onto the (inert) page behind the overlay.
    if (event.key === 'Tab') {
        trapTab(event);
    }
}

function focusable() {
    if (!panel.value) {
        return [];
    }

    return Array.from(
        panel.value.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
    );
}

function trapTab(event) {
    const items = focusable();
    if (items.length === 0) {
        event.preventDefault();
        return;
    }

    const first = items[0];
    const last = items[items.length - 1];
    const active = document.activeElement;

    if (event.shiftKey && active === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
    }
}

// Scroll-lock + listener lifecycle tied strictly to `open`, so toggling the
// prop is the single source of truth and unmount can never leave the body
// locked or a dangling key handler (a donor footgun).
//
// The body scroll-lock is reference-counted on a module-shared counter: when two
// scroll-locking surfaces overlap (a host modal under this auth modal), the
// first to close must not unlock the body while the second is still open. Each
// instance also remembers whether IT applied the lock, so it only decrements
// once regardless of how many times unlock() runs (open→unmount).
let lockedByThisInstance = false;
let previouslyFocused = null;

function lock() {
    document.addEventListener('keydown', onKeydown);

    if (!lockedByThisInstance) {
        lockedByThisInstance = true;
        scrollLockCount += 1;
    }
    // Idempotent: always reflect "at least one modal open" on the body, so the
    // class state can't drift from the counter even if something outside toggled
    // it. unlock() is the only place that clears it, and only at count zero.
    document.body.classList.add('overflow-hidden');

    // Remember the trigger so focus can return to it on close (a11y).
    previouslyFocused = document.activeElement;
}

function unlock() {
    document.removeEventListener('keydown', onKeydown);

    if (lockedByThisInstance) {
        lockedByThisInstance = false;
        scrollLockCount = Math.max(0, scrollLockCount - 1);
        if (scrollLockCount === 0) {
            document.body.classList.remove('overflow-hidden');
        }
    }

    // Restore focus to whatever held it before the modal opened.
    if (previouslyFocused) {
        previouslyFocused.focus?.();
        previouslyFocused = null;
    }
}

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            lock();
            // Move focus into the panel once it is rendered.
            requestAnimationFrame(() => {
                const items = focusable();
                (items[0] ?? panel.value)?.focus?.();
            });
        } else {
            unlock();
        }
    },
    { immediate: true },
);

onBeforeUnmount(unlock);
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="fixed inset-0 z-50 flex items-center justify-center p-4"
                role="dialog"
                aria-modal="true"
                :aria-label="title || undefined"
            >
                <div
                    class="absolute inset-0 bg-ink/50"
                    aria-hidden="true"
                    @click="onBackdrop"
                ></div>

                <div
                    ref="panel"
                    tabindex="-1"
                    class="relative w-full max-w-md rounded-md border border-border bg-surface p-6 shadow-lg outline-none"
                >
                    <button
                        type="button"
                        class="absolute right-3 top-3 rounded-sm p-1 text-ink-soft transition hover:bg-surface-subtle hover:text-ink"
                        :aria-label="$t('Close')"
                        @click="requestClose"
                    >
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">
                            <path d="M5 5l10 10M15 5L5 15" stroke-linecap="round" />
                        </svg>
                    </button>

                    <h2 v-if="title" class="mb-4 pr-6 text-xl font-semibold text-ink">{{ title }}</h2>

                    <slot />

                    <div v-if="$slots.footer" class="mt-6 text-center text-sm text-ink-soft">
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
