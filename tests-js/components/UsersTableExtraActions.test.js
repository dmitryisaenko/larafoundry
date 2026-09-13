import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// UsersTableActions reaches into usePage/router; stub the module like the sibling
// UsersTableSocial spec so the table mounts standalone.
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

const badgeCell = {
    key: 'access',
    label: 'Access',
    items: [
        { key: 'texts', text: 'T', tone: 'muted', title: 'AI texts: on' },
        { key: 'learner', text: 'L', tone: 'on' },
        { key: 'search', icon: 'search', tone: 'off', title: 'YouTube search: blocked' },
        { key: 'audio', icon: 'audio', tone: 'muted' },
    ],
};

describe('UsersTable host badge cells', () => {
    it('renders one control per badge, with captions and glyphs', () => {
        const wrapper = mount(UsersTable, {
            props: { users: [userRow([badgeCell])], userColumns: [] },
            ...globalMounts,
        });

        const buttons = wrapper.findAll('[data-extra-cell] button');
        expect(buttons).toHaveLength(4);
        // Letter badges carry their caption; icon badges carry an inline SVG.
        expect(buttons[0].text()).toBe('T');
        expect(buttons[1].text()).toBe('L');
        expect(buttons[2].find('svg').exists()).toBe(true);
        expect(buttons[3].find('svg').exists()).toBe(true);
        // A title becomes both the tooltip and the accessible name; without one
        // the caption stands in.
        expect(buttons[0].attributes('title')).toBe('AI texts: on');
        expect(buttons[0].attributes('aria-label')).toBe('AI texts: on');
        expect(buttons[1].attributes('aria-label')).toBe('L');
        expect(buttons[2].attributes('aria-label')).toBe('YouTube search: blocked');
    });

    it('emits extra-action with the user, the column key and the badge key', async () => {
        const wrapper = mount(UsersTable, {
            props: { users: [userRow([badgeCell])], userColumns: [] },
            ...globalMounts,
        });

        await wrapper.findAll('[data-extra-cell] button')[2].trigger('click');

        const emitted = wrapper.emitted('extra-action');
        expect(emitted).toHaveLength(1);
        expect(emitted[0][0].id).toBe(7);
        expect(emitted[0][1]).toBe('access');
        expect(emitted[0][2]).toBe('search');
    });

    it('renders a non-clickable badge as a plain span that emits nothing', async () => {
        const cell = {
            key: 'access',
            label: 'Access',
            items: [{ key: 'paid', text: '$', tone: 'on', clickable: false }],
        };
        const wrapper = mount(UsersTable, {
            props: { users: [userRow([cell])], userColumns: [] },
            ...globalMounts,
        });

        expect(wrapper.findAll('[data-extra-cell] button')).toHaveLength(0);

        const inert = wrapper.findAll('[data-extra-cell] span').find((s) => s.text() === '$');
        expect(inert).toBeTruthy();
        expect(inert.attributes('type')).toBeUndefined();
        // It must not even look like a control.
        expect(inert.classes().join(' ')).not.toContain('cursor-pointer');
        expect(inert.classes().join(' ')).not.toContain('hover:');

        await inert.trigger('click');
        expect(wrapper.emitted('extra-action')).toBeUndefined();
    });

    it('gives every badge the same minimum width so the column cannot jitter', () => {
        const wrapper = mount(UsersTable, {
            props: { users: [userRow([badgeCell])], userColumns: [] },
            ...globalMounts,
        });

        for (const button of wrapper.findAll('[data-extra-cell] button')) {
            expect(button.classes()).toContain('min-w-6');
        }
    });

    it('draws the glyph and drops the caption when a badge ships both', () => {
        const cell = {
            key: 'access',
            label: 'Access',
            items: [{ key: 'both', icon: 'search', text: 'S', tone: 'on' }],
        };
        const wrapper = mount(UsersTable, {
            props: { users: [userRow([cell])], userColumns: [] },
            ...globalMounts,
        });

        const button = wrapper.find('[data-extra-cell] button');
        expect(button.find('svg').exists()).toBe(true);
        expect(button.text()).toBe('');
    });

    it('still renders the original text cell (value + badge pill)', () => {
        const cell = { key: 'demo', label: 'Demo', value: 'Yes', badge: 'emerald' };
        const wrapper = mount(UsersTable, {
            props: { users: [userRow([cell])], userColumns: [] },
            ...globalMounts,
        });

        expect(wrapper.findAll('[data-extra-cell] button')).toHaveLength(0);
        const pill = wrapper.findAll('[data-extra-cell] span').find((s) => s.text() === 'Yes');
        expect(pill).toBeTruthy();
        expect(pill.classes().join(' ')).toContain('emerald');
    });

    it('survives an unknown tone and an unknown icon name', () => {
        const cell = {
            key: 'access',
            label: 'Access',
            items: [
                { key: 'weird', text: 'W', tone: 'chartreuse' },
                { key: 'ghost', icon: 'no-such-glyph', text: 'G', tone: 'on' },
                { key: 'blank', icon: 'no-such-glyph', tone: 'on' },
            ],
        };
        const wrapper = mount(UsersTable, {
            props: { users: [userRow([cell])], userColumns: [] },
            ...globalMounts,
        });

        const buttons = wrapper.findAll('[data-extra-cell] button');
        // Nothing is dropped: an unknown tone falls back to the neutral colour...
        expect(buttons).toHaveLength(3);
        expect(buttons[0].text()).toBe('W');
        expect(buttons[0].classes().join(' ')).toContain('text-ink-soft');
        // ...and an unknown glyph name falls back to the caption, or to an empty
        // (but still present and clickable) control when there is none.
        expect(buttons[1].find('svg').exists()).toBe(false);
        expect(buttons[1].text()).toBe('G');
        expect(buttons[2].find('svg').exists()).toBe(false);
        expect(buttons[2].text()).toBe('');
    });

    it('renders a dash for an empty items list, like an empty value', () => {
        const cell = { key: 'access', label: 'Access', items: [] };
        const wrapper = mount(UsersTable, {
            props: { users: [userRow([cell])], userColumns: [] },
            ...globalMounts,
        });

        expect(wrapper.findAll('[data-extra-cell] button')).toHaveLength(0);
        expect(wrapper.find('[data-extra-cell]').text()).toContain('-');
    });

    it('shows the dash, not a stale value, when a malformed cell carries both forms', () => {
        const cell = { key: 'access', label: 'Access', items: [], value: 'STALE' };
        const wrapper = mount(UsersTable, {
            props: { users: [userRow([cell])], userColumns: [] },
            ...globalMounts,
        });

        // Carrying `items` at all makes it a badge cell: `value` never surfaces.
        expect(wrapper.find('[data-extra-cell]').text()).toBe('-');
    });

    it('ignores items that are not a list instead of drawing ghost controls', () => {
        const cell = { key: 'access', label: 'Access', items: 'abc' };
        const wrapper = mount(UsersTable, {
            props: { users: [userRow([cell])], userColumns: [] },
            ...globalMounts,
        });

        // A string has a length and would iterate per character in a v-for.
        expect(wrapper.findAll('[data-extra-cell] button')).toHaveLength(0);
        expect(wrapper.find('[data-extra-cell]').text()).toBe('-');
    });

    it('skips holes in the items list instead of throwing the page away', () => {
        const cell = {
            key: 'access',
            label: 'Access',
            items: [null, { key: 'real', text: 'R', tone: 'on' }, 'nope'],
        };
        const wrapper = mount(UsersTable, {
            props: { users: [userRow([cell])], userColumns: [] },
            ...globalMounts,
        });

        const buttons = wrapper.findAll('[data-extra-cell] button');
        expect(buttons).toHaveLength(1);
        expect(buttons[0].text()).toBe('R');
    });

    it('renders badges that arrive without a key', () => {
        const cell = {
            key: 'access',
            label: 'Access',
            items: [{ text: 'A', tone: 'on' }, { text: 'B', tone: 'off' }],
        };
        const wrapper = mount(UsersTable, {
            props: { users: [userRow([cell])], userColumns: [] },
            ...globalMounts,
        });

        const buttons = wrapper.findAll('[data-extra-cell] button');
        expect(buttons).toHaveLength(2);
        expect(buttons[0].text()).toBe('A');
        expect(buttons[1].text()).toBe('B');
    });
});
