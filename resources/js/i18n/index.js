import { createI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';

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

    // Keep the active locale in sync with what the backend re-shares on every
    // Inertia visit. The LocaleSwitcher POSTs to change the session locale and
    // redirects back; `HandleInertiaRequests` then shares the new `locale` and
    // `translations` on the response. Without this bridge those props would land
    // but vue-i18n — built once at boot — would keep rendering the old language
    // until a full page reload (F5). Registering the freshly-shared messages here
    // is also what lets a host ship only the active language to the client.
    //
    // Bind both events: `success` covers server responses (the switch itself,
    // reloads, partial reloads); `navigate` covers history back/forward and
    // bfcache restores, which fire `navigate` only (no `success`). Both carry the
    // same `{ detail: { page } }` payload and `applyLocaleFromProps` is
    // idempotent, so the overlap on a normal forward visit is harmless.
    const sync = (event) => applyLocaleFromProps(i18n, event.detail.page?.props ?? {});
    router.on('success', sync);
    router.on('navigate', sync);

    return i18n;
}

/**
 * Adopt the locale and messages carried by an Inertia page's shared props.
 *
 * Registers the shared `translations` under the shared `locale` (so a language
 * the client has not seen yet gets its messages) and then activates that locale.
 * A no-op when no `locale` prop is present.
 *
 * @param {import('vue-i18n').I18n} i18n
 * @param {object} pageProps
 */
function applyLocaleFromProps(i18n, pageProps = {}) {
    const locale = pageProps.locale;
    if (!locale) {
        return;
    }

    if (pageProps.translations) {
        i18n.global.setLocaleMessage(locale, pageProps.translations);
    }

    if (i18n.global.locale.value !== locale) {
        i18n.global.locale.value = locale;
    }
}
