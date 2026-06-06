import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, enableAutoUnmount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

vi.mock('@inertiajs/vue3', () => ({
    router: { delete: vi.fn() },
}));

import SessionsManager from '../resources/js/Pages/Profile/sections/SessionsManager.vue';
import { router } from '@inertiajs/vue3';

const sessions = [
    { id: 1, browser: 'Chrome', os: 'macOS', ip_address: '1.1.1.1', login_method: 'native', is_current: true },
    { id: 2, browser: 'Firefox', os: 'Windows', ip_address: '2.2.2.2', login_method: 'native', is_current: false },
];

describe('SessionsManager', () => {
    beforeEach(() => router.delete.mockClear());

    it('lists every session', () => {
        const wrapper = mount(SessionsManager, { props: { sessions } });
        expect(wrapper.findAll('li')).toHaveLength(2);
    });

    it('does not offer a revoke button for the current device', () => {
        const wrapper = mount(SessionsManager, { props: { sessions } });
        // Only the non-current row has a per-session revoke button.
        expect(wrapper.findAll('li button')).toHaveLength(1);
    });

    it('revokes a chosen device', async () => {
        const wrapper = mount(SessionsManager, { props: { sessions } });
        await wrapper.find('li button').trigger('click');

        expect(router.delete).toHaveBeenCalledWith('/auth/sessions/2', { preserveScroll: true });
    });

    it('revokes all other devices', async () => {
        const wrapper = mount(SessionsManager, { props: { sessions } });
        const othersButton = wrapper.findAll('button').find((b) => b.text() === 'Sign out other devices');
        await othersButton.trigger('click');

        expect(router.delete).toHaveBeenCalledWith('/auth/sessions/others', { preserveScroll: true });
    });
});
