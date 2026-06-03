import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// Shared mock state for usePage props and router.post.
const post = vi.fn();
let pageProps = {};

vi.mock('@inertiajs/vue3', () => ({
    router: { post: (...args) => post(...args) },
    usePage: () => ({ props: pageProps }),
}));

import UsersTable from '../../resources/js/components/admin/UsersTable.vue';
import ImpersonationBanner from '../../resources/js/components/admin/ImpersonationBanner.vue';

const globalMounts = { global: { mocks: { $t: (key) => key } } };

const users = [
    { id: 1, name: 'Joe', lastname: 'Doe', email: 'joe@x.test', country: 'PL', companies_count: 2, registered_date: '01.01.2026', last_activity_human: '1 hour ago', is_admin: false, is_blocked: false, is_deleted: false, email_verified: true },
    { id: 2, name: 'Ann', lastname: 'Lee', email: 'ann@x.test', country: 'UA', companies_count: 0, registered_date: '02.01.2026', last_activity_human: '2 days ago', is_admin: false, is_blocked: true, is_deleted: false, email_verified: false },
];

describe('UsersTable', () => {
    it('renders a row per user', () => {
        const wrapper = mount(UsersTable, { props: { users }, ...globalMounts });
        expect(wrapper.findAll('tbody tr')).toHaveLength(2);
        expect(wrapper.text()).toContain('joe@x.test');
        expect(wrapper.text()).toContain('ann@x.test');
    });

    it('shows an empty state when there are no users', () => {
        const wrapper = mount(UsersTable, { props: { users: [] }, ...globalMounts });
        expect(wrapper.text()).toContain('No users found');
    });

    it('emits edit for a row', async () => {
        const wrapper = mount(UsersTable, { props: { users }, ...globalMounts });
        const editBtn = wrapper.findAll('button').find((b) => b.text() === 'Edit');
        await editBtn.trigger('click');
        expect(wrapper.emitted('edit')).toBeTruthy();
    });

    it('offers block for an active user and unblock for a blocked one', () => {
        const wrapper = mount(UsersTable, { props: { users }, ...globalMounts });
        const labels = wrapper.findAll('button').map((b) => b.text());
        expect(labels).toContain('Block');
        expect(labels).toContain('Unblock');
    });

    it('does not offer Follow for an admin user', () => {
        const adminRow = [{ ...users[0], is_admin: true }];
        const wrapper = mount(UsersTable, { props: { users: adminRow }, ...globalMounts });
        const labels = wrapper.findAll('button').map((b) => b.text());
        expect(labels).not.toContain('Follow');
    });
});

describe('ImpersonationBanner', () => {
    it('hides when not impersonating', () => {
        pageProps = { impersonating: false };
        const wrapper = mount(ImpersonationBanner, globalMounts);
        expect(wrapper.text()).toBe('');
    });

    it('shows and posts to leave when impersonating', async () => {
        post.mockClear();
        pageProps = { impersonating: true };
        const wrapper = mount(ImpersonationBanner, globalMounts);

        expect(wrapper.text()).toContain('You are impersonating another user.');

        await wrapper.find('button').trigger('click');
        expect(post).toHaveBeenCalledWith('/impersonate/leave');
    });
});
