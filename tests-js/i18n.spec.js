import { describe, it, expect, vi, beforeEach } from 'vitest';

/**
 * The locale bridge in installI18n: on every Inertia `success` it must adopt the
 * locale + translations the backend re-shares, so a language switch takes effect
 * without a full page reload (the regression this guards against).
 */
const handlers = {};
vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: (event, cb) => {
            handlers[event] = cb;
            return () => {};
        },
    },
}));

import { installI18n } from '../resources/js/i18n/index.js';

// A minimal Vue app stub: installI18n only calls app.use() and writes to
// config.globalProperties — i18n.global works standalone for the assertions.
function makeApp() {
    return { use: () => {}, config: { globalProperties: {} } };
}

function emit(event, props) {
    handlers[event]({ detail: { page: { props } } });
}

describe('installI18n locale bridge', () => {
    beforeEach(() => {
        for (const key of Object.keys(handlers)) {
            delete handlers[key];
        }
    });

    it('boots with the initial shared locale and translations', () => {
        const i18n = installI18n(makeApp(), { locale: 'en', translations: { Hello: 'Hello' } });

        expect(i18n.global.locale.value).toBe('en');
        expect(i18n.global.t('Hello')).toBe('Hello');
        // Both Inertia events drive the bridge (success + history navigate).
        expect(typeof handlers.success).toBe('function');
        expect(typeof handlers.navigate).toBe('function');
    });

    it('switches the active locale and registers new messages on an Inertia success', () => {
        const i18n = installI18n(makeApp(), { locale: 'en', translations: { Hello: 'Hello' } });

        emit('success', { locale: 'uk', translations: { Hello: 'Привіт' } });

        expect(i18n.global.locale.value).toBe('uk');
        expect(i18n.global.t('Hello')).toBe('Привіт');
    });

    it('also switches on a history navigate event (back/forward, bfcache)', () => {
        const i18n = installI18n(makeApp(), { locale: 'en', translations: { Hello: 'Hello' } });

        emit('navigate', { locale: 'uk', translations: { Hello: 'Привіт' } });

        expect(i18n.global.locale.value).toBe('uk');
        expect(i18n.global.t('Hello')).toBe('Привіт');
    });

    it('refreshes messages for the active locale (server-side cache invalidation)', () => {
        const i18n = installI18n(makeApp(), { locale: 'en', translations: { Hello: 'Hello' } });

        emit('success', { locale: 'en', translations: { Hello: 'Hello (edited)' } });

        expect(i18n.global.locale.value).toBe('en');
        expect(i18n.global.t('Hello')).toBe('Hello (edited)');
    });

    it('ignores a navigation that carries no locale prop', () => {
        const i18n = installI18n(makeApp(), { locale: 'en', translations: { Hello: 'Hello' } });

        emit('success', { translations: { Hello: 'should not apply' } });

        expect(i18n.global.locale.value).toBe('en');
        expect(i18n.global.t('Hello')).toBe('Hello');
    });
});
