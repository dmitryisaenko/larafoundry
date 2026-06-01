import { useI18n } from 'vue-i18n';

/**
 * Translation helper for use inside `<script setup>`.
 *
 * Returns the bound `t` function from vue-i18n. In templates you do not need
 * this — the core registers a global `$t` (see `installI18n`), so
 * `{{ $t('Some key') }}` works without any import, exactly like the legacy
 * global `t()`. Reach for `useT()` only when you need translation in script
 * logic.
 *
 * @example
 * const t = useT();
 * const label = t('Save');
 *
 * @returns {(key: string, ...args: any[]) => string}
 */
export function useT() {
    const { t } = useI18n();

    return t;
}
