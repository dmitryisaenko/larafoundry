import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

// Mock the Inertia router + page so we can assert the switch POST without the runtime.
const post = vi.fn();
const pageProps = { locale: 'en', available_locales: [] };
vi.mock('@inertiajs/vue3', () => ({
    router: { post: (...args) => post(...args) },
    usePage: () => ({ props: pageProps }),
}));

import LocaleSwitcher from '../../resources/js/components/LocaleSwitcher.vue';

// Ziggy's route() is a runtime global; echo the name so we can assert the call.
globalThis.route = (name) => name;

const locales = [
    { code: 'en', native: 'English', flag: '🇬🇧' },
    { code: 'uk', native: 'Українська', flag: '🇺🇦' },
];

describe('LocaleSwitcher', () => {
    beforeEach(() => {
        post.mockClear();
        pageProps.locale = 'en';
        pageProps.available_locales = [];
    });

    it('shows the active locale on the trigger', () => {
        const wrapper = mount(LocaleSwitcher, {
            props: { locales, current: 'en' },
        });

        expect(wrapper.text()).toContain('English');
    });

    it('lists locales when opened and switches to a different one', async () => {
        const wrapper = mount(LocaleSwitcher, {
            props: { locales, current: 'en' },
        });

        await wrapper.find('button').trigger('click'); // open dropdown

        const items = wrapper.findAll('li button');
        expect(items).toHaveLength(2);

        await items[1].trigger('click'); // Українська

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('larafoundry.language.switch');
        expect(post.mock.calls[0][1]).toEqual({ locale: 'uk' });
    });

    it('does not switch when the active locale is reselected', async () => {
        const wrapper = mount(LocaleSwitcher, {
            props: { locales, current: 'en' },
        });

        await wrapper.find('button').trigger('click');
        const items = wrapper.findAll('li button');
        await items[0].trigger('click'); // English = already active

        expect(post).not.toHaveBeenCalled();
    });

    it('reads locales and current from shared page props when no props are given', () => {
        pageProps.locale = 'uk';
        pageProps.available_locales = locales;

        const wrapper = mount(LocaleSwitcher);

        // Active is the shared 'uk'.
        expect(wrapper.text()).toContain('Українська');
    });

    it('renders nothing when there is only one locale', () => {
        const wrapper = mount(LocaleSwitcher, {
            props: { locales: [locales[0]], current: 'en' },
        });

        expect(wrapper.find('button').exists()).toBe(false);
    });
});
