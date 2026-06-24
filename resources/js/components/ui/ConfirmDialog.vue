<script>
// TRUE module scope (shared across every instance) — a `<script setup>` var is
// compiled into setup() and would be per-instance, defeating the election below.
import { reactive } from 'vue';

// Ids of currently-mounted ConfirmDialog instances; the lowest-id one renders.
const mountedInstances = reactive(new Set());
let instanceSeq = 0;

export default { name: 'ConfirmDialog' };
</script>

<script setup>
/**
 * Reusable confirm dialog (SweetAlert-style, dependency-free).
 *
 * Built on the core {@link Modal} (Teleport, scroll-lock, Esc/backdrop dismissal,
 * Tab focus-trap, focus restore) and driven by the {@link useConfirm} singleton —
 * call `confirm(options)` from anywhere and await a boolean. Mount this ONCE in a
 * root layout; the core AppLayout/AdminLayout already do, so host pages on the
 * core shell get it for free. A host with its own layout renders it once itself.
 *
 * Styling is token-only (neutral in the package; the host recolours via the same
 * semantic tokens), so it inherits the host palette and dark mode automatically.
 *
 * Every dismissal path resolves the promise `false`; only the confirm button
 * resolves `true`.
 */
import { computed, onMounted, onUnmounted } from 'vue';
import Modal from './Modal.vue';
import { settleConfirm, useConfirmState } from '../../composables/useConfirm.js';

// Labels fall back to the global `$t` IN THE TEMPLATE (not useT()/useI18n in
// setup): this component is mounted app-wide via the layouts, so depending on a
// composition i18n instance would force every page test to mock vue-i18n. The
// global `$t` is always present at runtime (installI18n) and stubbed in tests.
const state = useConfirmState();

// Render-once election (uses the module-scoped registry above). The dialog is
// intentionally mounted in more than one layout (core AppLayout + AdminLayout,
// and a host may add its own), but the state is a singleton, so two live
// instances would stack two overlays. Only the lowest-id mounted instance
// renders; it re-elects on unmount, so swapping layouts never leaves it unshown.
const instanceId = ++instanceSeq;
onMounted(() => mountedInstances.add(instanceId));
onUnmounted(() => mountedInstances.delete(instanceId));
const isPrimaryInstance = computed(() => {
    const ids = [...mountedInstances];
    return ids.length > 0 && Math.min(...ids) === instanceId;
});

const opts = computed(() => state.options ?? {});
const variant = computed(() => opts.value.variant ?? 'primary');

// Variant → icon-badge colour + confirm-button colour. Full literal class strings
// (so Tailwind's scanner emits them) using semantic tokens only.
const VARIANTS = {
    danger: {
        badge: 'bg-danger/10 text-danger',
        confirm: 'border-danger bg-danger text-white hover:opacity-90',
    },
    warning: {
        badge: 'bg-warning/10 text-warning',
        confirm: 'border-warning bg-warning text-white hover:opacity-90',
    },
    question: {
        badge: 'bg-brand-100 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300',
        confirm: 'border-brand-600 bg-brand-600 text-white hover:opacity-90',
    },
    primary: {
        badge: 'bg-brand-100 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300',
        confirm: 'border-brand-600 bg-brand-600 text-white hover:opacity-90',
    },
};

// Icon path(s) per variant (24×24, stroked). Arrays render as multiple <path>.
const ICONS = {
    danger: ['M12 3 2 20.5h20L12 3z', 'M12 10v4.5', 'M12 18h.01'],
    warning: ['M12 3 2 20.5h20L12 3z', 'M12 10v4.5', 'M12 18h.01'],
    question: ['M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z', 'M9.4 9.2a2.6 2.6 0 1 1 3.6 2.4c-.9.4-1.3 1-1.3 1.9', 'M12 17h.01'],
    primary: ['M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z', 'M12 11v5.5', 'M12 7.6h.01'],
};

const badgeClass = computed(() => (VARIANTS[variant.value] ?? VARIANTS.primary).badge);
const confirmClass = computed(() => (VARIANTS[variant.value] ?? VARIANTS.primary).confirm);
const iconPaths = computed(() => ICONS[variant.value] ?? ICONS.primary);

function onConfirm() {
    settleConfirm(true);
}
function onCancel() {
    settleConfirm(false);
}
</script>

<template>
    <Modal v-if="isPrimaryInstance" :open="state.open" @close="onCancel">
        <div class="text-center">
            <div class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-full" :class="badgeClass">
                <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path v-for="(d, i) in iconPaths" :key="i" :d="d" />
                </svg>
            </div>

            <h2 v-if="opts.title" class="mb-2 text-xl font-semibold text-ink">{{ opts.title }}</h2>
            <p v-if="opts.message" class="text-sm text-ink-soft">{{ opts.message }}</p>
            <p v-if="opts.highlight" class="mt-1 font-semibold text-ink">{{ opts.highlight }}</p>
            <p v-if="opts.detail" class="mt-1 text-xs text-ink-faint">{{ opts.detail }}</p>
        </div>

        <template #footer>
            <div class="flex justify-center gap-3">
                <button
                    type="button"
                    class="inline-flex h-10 min-w-24 items-center justify-center rounded-md border border-border bg-surface px-4 text-sm font-medium text-ink-soft transition hover:bg-surface-subtle"
                    @click="onCancel"
                >
                    {{ opts.cancelLabel || $t('Cancel') }}
                </button>
                <button
                    type="button"
                    class="inline-flex h-10 min-w-24 items-center justify-center rounded-md border px-4 text-sm font-medium transition"
                    :class="confirmClass"
                    @click="onConfirm"
                >
                    {{ opts.confirmLabel || $t('Confirm') }}
                </button>
            </div>
        </template>
    </Modal>
</template>
