import { createI18n } from 'vue-i18n';

/**
 * Build a vue-i18n instance from Inertia shared props.
 *
 * The backend (`HandleInertiaRequests`) shares `locale` and `translations`;
 * this mirrors the legacy host's setup but is packaged for reuse. Messages are
 * registered under the active locale only — Inertia re-shares them on locale
 * change, so there is no need to ship every language to the client.
 *
 * @param {object} pageProps  `props.initialPage.props` from Inertia setup
 * @returns {import('vue-i18n').I18n}
 */
export function createAppI18n(pageProps = {}) {
    const locale = pageProps.locale || 'en';
    const messages = pageProps.translations || {};

    return createI18n({
        legacy: false,
        locale,
        fallbackLocale: 'en',
        messages: {
            [locale]: messages,
        },
        // Translation keys are full English phrases; a missing key should fall
        // back to the key itself silently rather than warn on every render.
        missingWarn: false,
        fallbackWarn: false,
    });
}

/**
 * Install i18n into the app and expose translation globally.
 *
 * Preserves the legacy ergonomics so existing host code keeps working:
 *   - `{{ $t('key') }}` in any template, no import (Vue global property);
 *   - `t(...)` for plain, non-component JS via `globalThis.t`.
 *
 * Inside the package itself, prefer the `useT()` composable over the globals.
 *
 * @param {import('vue').App} app
 * @param {object} pageProps  `props.initialPage.props` from Inertia setup
 * @returns {import('vue-i18n').I18n}
 */
export function installI18n(app, pageProps = {}) {
    const i18n = createAppI18n(pageProps);

    app.use(i18n);

    const translate = (...args) => i18n.global.t(...args);

    // Templates: `{{ $t('key') }}` with no import.
    app.config.globalProperties.$t = translate;
    // Plain JS outside components (legacy compatibility).
    globalThis.t = translate;

    return i18n;
}
