<script setup>
/**
 * Right-hand slide-over filter drawer for the operator console.
 *
 * Legacy parity (kohana): the admin filters live behind a "Filters" button and
 * slide in from the right over a dimmed overlay, instead of an always-visible
 * inline control bar. Self-contained — a page renders it and toggles `open`
 * (v-model); it teleports its overlay + panel to <body>, so it is not clipped by
 * the page's stacking context and needs no layout plumbing. The panel body and
 * footer are slots, so each console screen supplies its own filter fields and
 * Clear / Apply buttons.
 *
 * Modal semantics mirror {@see Modal}: reference-counted body scroll-lock shared
 * with the other overlays, focus moved into the panel on open, Tab trapped inside
 * it, focus restored to the trigger on close, and Escape / overlay-click dismiss.
 */
import { ref, watch, onBeforeUnmount } from 'vue';
import { useScrollLock } from '../../composables/useScrollLock.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
});

const emit = defineEmits(['update:open']);

const panel = ref(null);
const { lock: lockScroll, unlock: unlockScroll } = useScrollLock();
let previouslyFocused = null;

function close() {
    emit('update:open', false);
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

function onKeydown(event) {
    if (event.key === 'Escape') {
        event.preventDefault();
        close();
        return;
    }
    if (event.key === 'Tab') {
        trapTab(event);
    }
}

function lock() {
    if (typeof document === 'undefined') {
        return;
    }
    document.addEventListener('keydown', onKeydown);
    lockScroll();
    previouslyFocused = document.activeElement;
    // Move focus into the panel once it is rendered.
    requestAnimationFrame(() => {
        const items = focusable();
        (items[0] ?? panel.value)?.focus?.();
    });
}

function unlock() {
    if (typeof document === 'undefined') {
        return;
    }
    document.removeEventListener('keydown', onKeydown);
    unlockScroll();
    if (previouslyFocused) {
        previouslyFocused.focus?.();
        previouslyFocused = null;
    }
}

// `immediate` so a drawer mounted already-open still locks, binds Esc and moves
// focus; unlock() is per-instance guarded (via useScrollLock) so unmount while
// open never leaves the body locked or a dangling handler.
watch(
    () => props.open,
    (isOpen) => (isOpen ? lock() : unlock()),
    { immediate: true },
);

onBeforeUnmount(unlock);
</script>

<template>
    <Teleport to="body">
        <Transition name="lf-drawer-fade">
            <div
                v-if="open"
                class="fixed inset-0 z-40 bg-ink/50"
                aria-hidden="true"
                @click="close"
            />
        </Transition>

        <Transition name="lf-drawer-slide">
            <aside
                v-if="open"
                ref="panel"
                tabindex="-1"
                class="fixed inset-y-0 right-0 z-50 flex w-full max-w-sm flex-col bg-surface shadow-xl outline-none"
                role="dialog"
                aria-modal="true"
                aria-labelledby="lf-drawer-title"
            >
                <header class="flex items-center justify-between border-b border-border px-5 py-4">
                    <h2 id="lf-drawer-title" class="text-base font-semibold text-ink">{{ title }}</h2>
                    <button
                        type="button"
                        class="rounded-sm p-1 text-ink-soft transition hover:bg-surface-subtle hover:text-ink"
                        :aria-label="$t('Close')"
                        @click="close"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M18 6 6 18M6 6l12 12" />
                        </svg>
                    </button>
                </header>

                <div class="flex-1 space-y-6 overflow-y-auto px-5 py-5">
                    <slot />
                </div>

                <footer v-if="$slots.footer" class="border-t border-border px-5 py-4">
                    <slot name="footer" />
                </footer>
            </aside>
        </Transition>
    </Teleport>
</template>

<style scoped>
.lf-drawer-fade-enter-active,
.lf-drawer-fade-leave-active {
    transition: opacity 0.25s ease;
}
.lf-drawer-fade-enter-from,
.lf-drawer-fade-leave-to {
    opacity: 0;
}

.lf-drawer-slide-enter-active,
.lf-drawer-slide-leave-active {
    transition: transform 0.25s ease;
}
.lf-drawer-slide-enter-from,
.lf-drawer-slide-leave-to {
    transform: translateX(100%);
}
</style>
