import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// UsersTableActions reaches into usePage/router; stub the module like the sibling
// AdminConsole spec so the table mounts standalone.
vi.mock('@inertiajs/vue3', () => ({
    router: { post: vi.fn() },
    usePage: () => ({ props: {} }),
}));

import UsersTable from '../../resources/js/components/admin/UsersTable.vue';

const globalMounts = { global: { mocks: { $t: (key) => key } } };

const users = [
    {
        id: 1,
        name: 'Joe',
        lastname: 'Doe',
        email: 'joe@x.test',
        country: 'PL',
        owned_companies_count: 0,
        employee_companies_count: 0,
        registered_date: '01.01.2026',
        last_activity_human: '1 hour ago',
        is_admin: false,
        is_blocked: false,
        is_deleted: false,
        email_verified: true,
        social_links: [
            { platform: 'github', url: 'https://github.com/joe' },
            { platform: 'website', url: 'https://joe.test' },
        ],
    },
];

describe('UsersTable social column', () => {
    it('hides the social column when the token is off', () => {
        const wrapper = mount(UsersTable, {
            props: { users, userColumns: [] },
            ...globalMounts,
        });
        expect(wrapper.find('a[rel="noopener noreferrer"]').exists()).toBe(false);
    });

    it('renders social links as hardened anchors when the token is on', () => {
        const wrapper = mount(UsersTable, {
            props: { users, userColumns: ['social'] },
            ...globalMounts,
        });

        const links = wrapper.findAll('a[rel="noopener noreferrer"]');
        expect(links).toHaveLength(2);
        // Every social link opens in a new tab with the opener severed and
        // carries a real per-platform SVG icon (no v-html).
        for (const a of links) {
            expect(a.attributes('target')).toBe('_blank');
            expect(a.attributes('rel')).toBe('noopener noreferrer');
            expect(a.find('svg').exists()).toBe(true);
        }
        expect(links[0].attributes('href')).toBe('https://github.com/joe');
    });
});
