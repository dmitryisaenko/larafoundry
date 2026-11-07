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

import { eastSlavicPlural, installI18n, polishPlural, zeroIsOnePlural } from '../resources/js/i18n/index.js';

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

    it('picks the East Slavic plural form for a three-form message', () => {
        const key = '{n} credit | {n} credits';
        const i18n = installI18n(makeApp(), {
            locale: 'uk',
            translations: { [key]: '{n} кредит | {n} кредити | {n} кредитів' },
        });

        const cases = { 1: 'кредит', 2: 'кредити', 4: 'кредити', 5: 'кредитів', 11: 'кредитів', 12: 'кредитів', 14: 'кредитів', 21: 'кредит', 22: 'кредити', 25: 'кредитів', 111: 'кредитів', 0: 'кредитів' };

        for (const [n, word] of Object.entries(cases)) {
            expect(i18n.global.t(key, Number(n))).toBe(`${n} ${word}`);
        }
    });

    it('keeps one/other for English and for a two-form message', () => {
        const key = '{n} credit | {n} credits';
        const i18n = installI18n(makeApp(), { locale: 'en', translations: { [key]: key } });

        expect(i18n.global.t(key, 1)).toBe('1 credit');
        expect(i18n.global.t(key, 5)).toBe('5 credits');
        expect(eastSlavicPlural(1, 2)).toBe(0);
        expect(eastSlavicPlural(3, 2)).toBe(1);
    });

    it('picks the Polish plural form, where 21 is not singular', () => {
        const key = '{n} credit | {n} credits';
        const i18n = installI18n(makeApp(), {
            locale: 'pl',
            translations: { [key]: '{n} kredyt | {n} kredyty | {n} kredytów' },
        });

        const cases = { 1: 'kredyt', 2: 'kredyty', 4: 'kredyty', 5: 'kredytów', 12: 'kredytów', 14: 'kredytów', 21: 'kredytów', 22: 'kredyty', 25: 'kredytów', 104: 'kredyty', 112: 'kredytów', 0: 'kredytów' };

        for (const [n, word] of Object.entries(cases)) {
            expect(i18n.global.t(key, Number(n))).toBe(`${n} ${word}`);
        }

        expect(polishPlural(21, 2)).toBe(1);
        expect(polishPlural(1, 2)).toBe(0);
    });

    it('reads 0 as singular in French and Portuguese', () => {
        const key = '{n} credit | {n} credits';

        for (const [locale, one, many] of [['fr', 'crédit', 'crédits'], ['pt', 'crédito', 'créditos']]) {
            const i18n = installI18n(makeApp(), { locale, translations: { [key]: `{n} ${one} | {n} ${many}` } });

            expect(i18n.global.t(key, 0)).toBe(`0 ${one}`);
            expect(i18n.global.t(key, 1)).toBe(`1 ${one}`);
            expect(i18n.global.t(key, 2)).toBe(`2 ${many}`);
        }

        // A three-form message keeps vue-i18n's own none | one | many reading.
        expect(zeroIsOnePlural(0, 3)).toBe(0);
        expect(zeroIsOnePlural(1, 3)).toBe(1);
        expect(zeroIsOnePlural(7, 3)).toBe(2);
    });

    it('ignores a navigation that carries no locale prop', () => {
        const i18n = installI18n(makeApp(), { locale: 'en', translations: { Hello: 'Hello' } });

        emit('success', { translations: { Hello: 'should not apply' } });

        expect(i18n.global.locale.value).toBe('en');
        expect(i18n.global.t('Hello')).toBe('Hello');
    });
});
