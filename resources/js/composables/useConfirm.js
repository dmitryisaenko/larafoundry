import { reactive } from 'vue';

/**
 * Promise-based confirm dialog, in the spirit of `Swal.fire` but dependency-free
 * and theme-native (it renders through the core {@link ConfirmDialog}, which is
 * built on the core Modal and styled with the same semantic tokens, so it adopts
 * the host palette and dark mode for free).
 *
 * A single module-level state drives ONE mounted `<ConfirmDialog />` per app, so
 * `confirm()` can be called from anywhere (a page action, a composable) without
 * each caller wiring its own overlay. Mount the dialog once in your root layout;
 * the core AppLayout/AdminLayout already do.
 *
 * @example
 * import { confirm } from '@dmitryisaenko/larafoundry';
 * const ok = await confirm({
 *   variant: 'danger',
 *   title: t('Delete item'),
 *   message: t('This cannot be undone.'),
 *   highlight: item.name,
 *   confirmLabel: t('Delete'),
 * });
 * if (ok) destroy();
 */

// Shared singleton: one open dialog at a time per application.
const state = reactive({
    open: false,
    options: {},
    _resolve: null,
});

/**
 * Open the confirm dialog. Resolves `true` when confirmed, `false` when the user
 * cancels or dismisses (Esc / backdrop / close button). All copy is passed
 * already-translated, so the composable stays domain- and i18n-agnostic.
 *
 * @param {object} [options]
 * @param {'danger'|'warning'|'question'|'primary'} [options.variant='primary'] colour + icon
 * @param {string} [options.title]        heading
 * @param {string} [options.message]      body line
 * @param {string} [options.detail]       secondary muted line
 * @param {string} [options.highlight]    emphasised line (e.g. the record name)
 * @param {string} [options.confirmLabel] confirm button text (defaults to t('Confirm'))
 * @param {string} [options.cancelLabel]  cancel button text (defaults to t('Cancel'))
 * @returns {Promise<boolean>}
 */
export function confirm(options = {}) {
    // If a previous dialog is somehow still open, resolve it false before reusing.
    if (state._resolve) {
        const stale = state._resolve;
        state._resolve = null;
        stale(false);
    }

    state.options = options;
    state.open = true;

    return new Promise((resolve) => {
        state._resolve = resolve;
    });
}

/**
 * Settle and close the active dialog. Used by {@link ConfirmDialog}; not part of
 * the public calling surface.
 *
 * @param {boolean} result
 */
export function settleConfirm(result) {
    state.open = false;
    const resolve = state._resolve;
    state._resolve = null;
    resolve?.(result);
}

/**
 * The shared reactive state, for {@link ConfirmDialog} to render from.
 */
export function useConfirmState() {
    return state;
}
