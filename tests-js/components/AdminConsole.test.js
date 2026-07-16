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
import BlockUserDialog from '../../resources/js/components/admin/BlockUserDialog.vue';
import ImpersonationBanner from '../../resources/js/components/admin/ImpersonationBanner.vue';

const globalMounts = { global: { mocks: { $t: (key) => key } } };

const users = [
    { id: 1, name: 'Joe', lastname: 'Doe', email: 'joe@x.test', country: 'PL', owned_companies_count: 2, employee_companies_count: 1, registered_date: '01.01.2026', last_activity_human: '1 hour ago', is_admin: false, is_blocked: false, is_deleted: false, email_verified: true },
    { id: 2, name: 'Ann', lastname: 'Lee', email: 'ann@x.test', country: 'UA', owned_companies_count: 0, employee_companies_count: 0, registered_date: '02.01.2026', last_activity_human: '2 days ago', is_admin: false, is_blocked: true, is_deleted: false, email_verified: false },
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
        const editBtn = wrapper.findAll('button').find((b) => b.attributes('aria-label') === 'Edit');
        await editBtn.trigger('click');
        expect(wrapper.emitted('edit')).toBeTruthy();
    });

    it('offers block for an active user and unblock for a blocked one', () => {
        const wrapper = mount(UsersTable, { props: { users }, ...globalMounts });
        const labels = wrapper.findAll('button').map((b) => b.attributes('aria-label'));
        expect(labels).toContain('Block');
        expect(labels).toContain('Unblock');
    });

    it('does not offer Follow for an admin user', () => {
        const adminRow = [{ ...users[0], is_admin: true }];
        const wrapper = mount(UsersTable, { props: { users: adminRow }, ...globalMounts });
        const labels = wrapper.findAll('button').map((b) => b.attributes('aria-label'));
        expect(labels).not.toContain('Follow');
    });

    it('always shows ID and the split Comp. / Empl. column', () => {
        const wrapper = mount(UsersTable, { props: { users }, ...globalMounts });
        const headers = wrapper.findAll('thead th').map((h) => h.text());
        expect(headers).toContain('ID');
        expect(headers).toContain('Comp. / Empl.');
        // Row 1 owns 2, is an employee in 1.
        expect(wrapper.find('tbody tr').text()).toContain('2 / 1');
    });

    it('hides phone/sex/age columns when no tokens are opted in', () => {
        const wrapper = mount(UsersTable, { props: { users }, ...globalMounts });
        const headers = wrapper.findAll('thead th').map((h) => h.text());
        expect(headers).not.toContain('Phone');
        expect(headers).not.toContain('Sex');
        expect(headers).not.toContain('Age');
    });

    it('renders phone/sex/age columns for the opted-in tokens', () => {
        const rows = [{ ...users[0], phone: '+100', phone_verified: true, sex: 'male', age: 33 }];
        const wrapper = mount(UsersTable, {
            props: { users: rows, userColumns: ['phone', 'sex', 'age'] },
            ...globalMounts,
        });
        const headers = wrapper.findAll('thead th').map((h) => h.text());
        // Phone folds into the combined Email / Phone cell (legacy parity); Sex and
        // Age remain their own columns.
        expect(headers).toContain('Email / Phone');
        expect(headers).toContain('Sex');
        expect(headers).toContain('Age');
        expect(wrapper.text()).toContain('+100');
        expect(wrapper.text()).toContain('33');
    });

    it('offers phone verify actions only when the phone token is on', () => {
        const rows = [{ ...users[0], phone_verified: false }];
        const off = mount(UsersTable, { props: { users: rows }, ...globalMounts });
        expect(off.findAll('button').map((b) => b.attributes('aria-label'))).not.toContain('Verify phone');

        const on = mount(UsersTable, { props: { users: rows, userColumns: ['phone'] }, ...globalMounts });
        expect(on.findAll('button').map((b) => b.attributes('aria-label'))).toContain('Verify phone');
    });
});

describe('BlockUserDialog', () => {
    const user = { id: 1, name: 'Joe', lastname: 'Doe', email: 'joe@x.test' };
    // The dialog teleports through the core Modal; stub teleport so its content
    // renders in place and stays assertable.
    const dialogMounts = { global: { ...globalMounts.global, stubs: { teleport: true } } };

    it('emits the trimmed reason and numeric code on submit', async () => {
        const wrapper = mount(BlockUserDialog, { props: { open: true, user }, ...dialogMounts });
        await wrapper.find('textarea').setValue('  spam  ');
        await wrapper.find('input[type="number"]').setValue('5');

        const blockBtn = wrapper.findAll('button').find((b) => b.text() === 'Block');
        await blockBtn.trigger('click');

        expect(wrapper.emitted('submit')[0][0]).toEqual({ reason: 'spam', block_code: 5 });
    });

    it('submits a null code when the code field is left blank', async () => {
        const wrapper = mount(BlockUserDialog, { props: { open: true, user }, ...dialogMounts });
        await wrapper.find('textarea').setValue('abuse');

        const blockBtn = wrapper.findAll('button').find((b) => b.text() === 'Block');
        await blockBtn.trigger('click');

        expect(wrapper.emitted('submit')[0][0]).toEqual({ reason: 'abuse', block_code: null });
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
