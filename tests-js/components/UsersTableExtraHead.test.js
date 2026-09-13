import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// UsersTableActions reaches into usePage/router; stub the module like the sibling
// specs so the table mounts standalone.
vi.mock('@inertiajs/vue3', () => ({
    router: { post: vi.fn() },
    usePage: () => ({ props: {} }),
}));

import UsersTable from '../../resources/js/components/admin/UsersTable.vue';

const globalMounts = { global: { mocks: { $t: (key) => key } } };

function userRow(extraColumns) {
    return {
        id: 7,
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
        extra_columns: extraColumns,
    };
}

const ITEMS = [{ key: 'texts', text: 'T', tone: 'on' }];

function mountWithColumn(column) {
    return mount(UsersTable, {
        props: { users: [userRow([column])], userColumns: [] },
        ...globalMounts,
    });
}

// The host column's header is the last <th> before Actions.
function hostHeader(wrapper) {
    const headers = wrapper.findAll('thead th');
    return headers[headers.length - 2];
}

describe('UsersTable host column header', () => {
    it('renders the caption when the column ships no head (existing hosts)', () => {
        const wrapper = mountWithColumn({ key: 'access', label: 'Access', items: ITEMS });

        const th = hostHeader(wrapper);
        expect(th.text()).toBe('Access');
        expect(th.find('svg').exists()).toBe(false);
        // No badges, so no tooltip is needed: the caption is right there.
        expect(th.attributes('title')).toBeUndefined();
    });

    it('renders head badges and keeps the caption reachable', () => {
        const wrapper = mountWithColumn({
            key: 'access',
            label: 'Access',
            head: [
                { key: 'texts', text: 'T', tone: 'on', title: 'AI texts: open to everyone' },
                { key: 'search', icon: 'search', tone: 'muted', title: 'Search: closed' },
            ],
            items: ITEMS,
        });

        const th = hostHeader(wrapper);
        // The caption is gone from sight but not from the accessibility tree.
        expect(th.text()).toBe('T');
        expect(th.attributes('title')).toBe('Access');
        expect(th.attributes('aria-label')).toBe('Access');

        const badges = th.findAll('span[title]');
        expect(badges).toHaveLength(2);
        expect(badges[0].text()).toBe('T');
        expect(badges[0].attributes('title')).toBe('AI texts: open to everyone');
        expect(badges[1].find('svg').exists()).toBe(true);
    });

    it('shares the row tone palette, so a colour means the same in both places', () => {
        const wrapper = mountWithColumn({
            key: 'access',
            label: 'Access',
            head: [{ key: 'a', text: 'A', tone: 'on' }, { key: 'b', text: 'B', tone: 'off' }],
            items: [{ key: 'a', text: 'A', tone: 'on' }],
        });

        const headBadges = hostHeader(wrapper).findAll('span.font-mono');
        const rowBadge = wrapper.find('[data-extra-cell] button');

        expect(headBadges[0].classes().join(' ')).toContain('emerald');
        expect(headBadges[1].classes().join(' ')).toContain('rose');
        // Same token, same class as the row badge below it.
        expect(rowBadge.classes().join(' ')).toContain('emerald');
    });

    it('never makes a head badge clickable, even when the host asks', async () => {
        const wrapper = mountWithColumn({
            key: 'access',
            label: 'Access',
            head: [{ key: 'texts', text: 'T', tone: 'on', clickable: true }],
            items: ITEMS,
        });

        const th = hostHeader(wrapper);
        expect(th.find('button').exists()).toBe(false);

        await th.find('span.font-mono').trigger('click');
        expect(wrapper.emitted('extra-action')).toBeUndefined();
    });

    it('falls back to the caption when head is malformed', () => {
        for (const head of ['текст', 42, [null, 'nope'], {}]) {
            const wrapper = mountWithColumn({ key: 'access', label: 'Access', head, items: ITEMS });
            const th = hostHeader(wrapper);

            expect(th.text()).toBe('Access');
            expect(th.find('svg').exists()).toBe(false);
        }
    });

    it('gives every head badge an accessible name of its own', () => {
        const wrapper = mountWithColumn({
            key: 'access',
            label: 'Access',
            head: [
                { key: 'texts', text: 'T', tone: 'on', title: 'AI texts: open' },
                { key: 'learner', text: 'L', tone: 'off' },
                { key: 'search', icon: 'search', tone: 'muted' },
            ],
            items: ITEMS,
        });

        // The glyph itself is aria-hidden, so without this a header badge would
        // be silent to a screen reader while the same badge one row below speaks.
        const badges = hostHeader(wrapper).findAll('span.font-mono');
        expect(badges[0].attributes('aria-label')).toBe('AI texts: open');
        expect(badges[1].attributes('aria-label')).toBe('L');
        expect(badges[2].attributes('aria-label')).toBe('search');
    });

    it('falls back to the caption for an empty head', () => {
        const wrapper = mountWithColumn({ key: 'access', label: 'Access', head: [], items: ITEMS });

        // An empty set is not a header: the column keeps its name rather than
        // showing a blank header cell.
        expect(hostHeader(wrapper).text()).toBe('Access');
    });

    it('keeps the badges but drops the tooltip when a column has no label', () => {
        const wrapper = mountWithColumn({
            key: 'access',
            head: [{ key: 'a', text: 'A', tone: 'on' }],
            items: ITEMS,
        });

        const th = hostHeader(wrapper);
        expect(th.text()).toBe('A');
        // No caption to offer, so no empty tooltip is invented.
        expect(th.attributes('title')).toBeUndefined();
    });

    it('skips holes in head instead of rendering empty badges', () => {
        const wrapper = mountWithColumn({
            key: 'access',
            label: 'Access',
            head: [null, { key: 'a', text: 'A', tone: 'on' }, 'junk', ['nested']],
            items: ITEMS,
        });

        expect(hostHeader(wrapper).findAll('span.font-mono')).toHaveLength(1);
    });

    it('takes head from the first row that CARRIES it, not the first row', () => {
        const wrapper = mount(UsersTable, {
            props: {
                users: [
                    // A host that builds `head` conditionally can easily omit it
                    // on whichever user happens to sort first.
                    userRow([{ key: 'access', label: 'Access', items: ITEMS }]),
                    { ...userRow([{ key: 'access', label: 'Access', head: [{ key: 'a', text: 'A', tone: 'on' }], items: ITEMS }]), id: 8 },
                ],
                userColumns: [],
            },
            ...globalMounts,
        });

        expect(hostHeader(wrapper).text()).toBe('A');
    });

    it('takes head from the first row that carries it', () => {
        const wrapper = mount(UsersTable, {
            props: {
                users: [
                    userRow([{ key: 'access', label: 'Access', head: [{ key: 'a', text: 'A', tone: 'on' }], items: ITEMS }]),
                    { ...userRow([{ key: 'access', label: 'Access', head: [{ key: 'z', text: 'Z', tone: 'off' }], items: ITEMS }]), id: 8 },
                ],
                userColumns: [],
            },
            ...globalMounts,
        });

        expect(hostHeader(wrapper).text()).toBe('A');
    });
});
