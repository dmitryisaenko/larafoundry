import { config } from '@vue/test-utils';

/**
 * Global test setup.
 *
 * Components reference the global `$t(...)` registered at runtime by the core's
 * `installI18n`. Tests don't boot the full i18n plugin, so we stub `$t` as an
 * identity function (returns the key). This is deterministic and keeps assertions
 * readable — a rendered label equals the translation key passed in.
 *
 * Components that translate inside script logic use `useT()` (which calls
 * vue-i18n's `useI18n`); those specs mock the `vue-i18n` module locally.
 */
config.global.mocks.$t = (key) => key;
