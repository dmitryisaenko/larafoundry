import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

// Inertia runtime mock: spy on the preference mutations and stub Link.
const put = vi.fn();
const post = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        put: (...args) => put(...args),
        post: (...args) => post(...args),
    },
    usePage: () => ({
        props: {
            auth: { user: { name: 'Boss', email: 'boss@x.test', avatar_url: null } },
            ui_settings: { theme: 'light' },
            available_locales: [
                { code: 'en', native: 'English', flag: '🇬🇧' },
                { code: 'uk', native: 'Українська', flag: '🇺🇦' },
            ],
            locale: 'en',
        },
    }),
    Link: {
        name: 'Link',
        props: ['href', 'method', 'as'],
        template: '<a :href="href"><slot /></a>',
    },
}));

import OperatorPullout from '../../resources/js/components/admin/OperatorPullout.vue';

// Stub the drawer's Teleport so its content renders in place and stays assertable.
const mounts = { global: { stubs: { teleport: true } } };

async function openPullout(wrapper) {
    await wrapper.get('button[aria-label="Super-admin"]').trigger('click');
}

describe('OperatorPullout', () => {
    beforeEach(() => {
        put.mockClear();
        post.mockClear();
    });

    it('renders the trigger avatar button', () => {
        const wrapper = mount(OperatorPullout, mounts);
        expect(wrapper.find('button[aria-label="Super-admin"]').exists()).toBe(true);
    });

    it('shows the operator identity, the profile link, and a native logout form when open', async () => {
        const wrapper = mount(OperatorPullout, mounts);
        await openPullout(wrapper);

        expect(wrapper.text()).toContain('Boss');
        expect(wrapper.text()).toContain('boss@x.test');

        const hrefs = wrapper.findAll('a').map((a) => a.attributes('href'));
        expect(hrefs).toContain('/admin/profile');

        // Logout is a native form POST (not an Inertia Link): it triggers a
        // full-page navigation, so the redirect to a non-Inertia guest landing
        // never pops Inertia's error-modal overlay.
        const logout = wrapper.find('form[action="/logout"]');
        expect(logout.exists()).toBe(true);
        expect(logout.attributes('method')).toBe('POST');
    });

    it('persists the theme (save-only) via the ui-settings endpoint', async () => {
        const wrapper = mount(OperatorPullout, mounts);
        await openPullout(wrapper);

        const themeBtn = wrapper
            .findAll('button')
            .find((b) => b.attributes('aria-label') === 'Switch to dark mode');
        await themeBtn.trigger('click');

        expect(put).toHaveBeenCalledTimes(1);
        const [url, payload] = put.mock.calls[0];
        expect(url).toBe('/profile/ui-settings');
        expect(payload).toMatchObject({ key: 'theme', value: 'dark' });
    });

    it('switches language through the language route', async () => {
        const wrapper = mount(OperatorPullout, mounts);
        await openPullout(wrapper);

        // Expand the inline locale list, then pick the non-active locale.
        await wrapper
            .findAll('button')
            .find((b) => b.attributes('aria-label') === 'Language')
            .trigger('click');

        const ukBtn = wrapper.findAll('button').find((b) => b.text().includes('Українська'));
        await ukBtn.trigger('click');

        expect(post).toHaveBeenCalledWith('/larafoundry/language', { locale: 'uk' }, expect.anything());
    });
});
