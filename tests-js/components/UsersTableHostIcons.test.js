import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// UsersTableActions reaches into usePage/router; stub the module like the sibling
// specs so the table mounts standalone.
vi.mock('@inertiajs/vue3', () => ({
    router: { post: vi.fn() },
    usePage: () => ({ props: {} }),
}));

import UsersTable from '../../resources/js/components/admin/UsersTable.vue';
import { MAX_ICON_PATH_LENGTH } from '../../resources/js/components/admin/cellIcons.js';

const globalMounts = { global: { mocks: { $t: (key) => key } } };

const HOST_PATH = 'M4 3h10l6 6v12a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1z';

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

function mountWithItems(items) {
    return mount(UsersTable, {
        props: { users: [userRow([{ key: 'access', label: 'Access', items }])], userColumns: [] },
        ...globalMounts,
    });
}

describe('host-supplied badge glyphs', () => {
    it('draws a glyph named from the common set', () => {
        const wrapper = mountWithItems([{ key: 'search', icon: 'search', tone: 'on' }]);

        const svg = wrapper.find('[data-extra-cell] svg');
        expect(svg.exists()).toBe(true);
        expect(svg.attributes('viewBox')).toBe('0 0 24 24');
        expect(svg.find('path').attributes('d')).toContain('M11 4a7 7');
    });

    it("draws the host's own glyph from path data", () => {
        const wrapper = mountWithItems([
            { key: 'word', icon: { path: HOST_PATH }, tone: 'on', title: 'Docs' },
        ]);

        const svg = wrapper.find('[data-extra-cell] svg');
        expect(svg.exists()).toBe(true);
        // The path travels as the `d` ATTRIBUTE, never as markup.
        expect(svg.find('path').attributes('d')).toBe(HOST_PATH);
        expect(svg.attributes('viewBox')).toBe('0 0 24 24');
        expect(svg.attributes('fill')).toBe('currentColor');
        // Still a working control: tone and tooltip apply as for a named glyph.
        const button = wrapper.find('[data-extra-cell] button');
        expect(button.attributes('title')).toBe('Docs');
        expect(button.classes().join(' ')).toContain('emerald');
    });

    it('emits extra-action for a host glyph badge like any other', async () => {
        const wrapper = mountWithItems([{ key: 'word', icon: { path: HOST_PATH }, tone: 'on' }]);

        await wrapper.find('[data-extra-cell] button').trigger('click');

        expect(wrapper.emitted('extra-action')[0][2]).toBe('word');
    });

    it('takes a host view_box of four numbers and ignores anything else', () => {
        const ok = mountWithItems([
            { key: 'a', icon: { path: HOST_PATH, view_box: '0 0 16 16' }, tone: 'on' },
        ]);
        expect(ok.find('[data-extra-cell] svg').attributes('viewBox')).toBe('0 0 16 16');

        // Fractions and negative origins are four numbers too.
        const fractional = mountWithItems([
            { key: 'a', icon: { path: HOST_PATH, view_box: '-1.5 -1.5 24.5 24.5' }, tone: 'on' },
        ]);
        expect(fractional.find('[data-extra-cell] svg').attributes('viewBox')).toBe('-1.5 -1.5 24.5 24.5');

        for (const junk of ['нечто', '0 0 24', '0 0 24 24 24', '0,0,24,24', '0 0 24 24 ', 42]) {
            const wrapper = mountWithItems([
                { key: 'a', icon: { path: HOST_PATH, view_box: junk }, tone: 'on' },
            ]);
            expect(wrapper.find('[data-extra-cell] svg').attributes('viewBox')).toBe('0 0 24 24');
        }
    });

    it('draws an outline glyph with a stroke and no fill', () => {
        const wrapper = mountWithItems([
            { key: 'a', icon: { path: HOST_PATH, stroke_width: 1.5 }, tone: 'on' },
        ]);

        const svg = wrapper.find('[data-extra-cell] svg');
        expect(svg.attributes('fill')).toBe('none');
        expect(svg.attributes('stroke')).toBe('currentColor');
        expect(svg.attributes('stroke-width')).toBe('1.5');
    });

    it('degrades an over-long path to the caption, leaving the other badges alone', () => {
        const wrapper = mountWithItems([
            { key: 'huge', icon: { path: 'M'.repeat(MAX_ICON_PATH_LENGTH + 1) }, text: 'D', tone: 'on' },
            { key: 'fine', icon: { path: HOST_PATH }, tone: 'on' },
            { key: 'letter', text: 'T', tone: 'off' },
        ]);

        const buttons = wrapper.findAll('[data-extra-cell] button');
        expect(buttons).toHaveLength(3);
        // The oversized glyph is not drawn; its caption stands in.
        expect(buttons[0].find('svg').exists()).toBe(false);
        expect(buttons[0].text()).toBe('D');
        // Its neighbours are untouched.
        expect(buttons[1].find('svg').exists()).toBe(true);
        expect(buttons[2].text()).toBe('T');
    });

    it('draws a path of exactly the maximum length', () => {
        const wrapper = mountWithItems([
            { key: 'edge', icon: { path: 'M'.repeat(MAX_ICON_PATH_LENGTH) }, text: 'D', tone: 'on' },
        ]);

        // The ceiling is inclusive: only what is OVER it degrades.
        expect(wrapper.find('[data-extra-cell] svg').exists()).toBe(true);
    });

    it('ignores a stroke width that would draw nothing', () => {
        // A zero (or blank) stroke suppresses the fill and then draws no line —
        // an invisible badge. It has to read as "no stroke given" instead.
        for (const stroke of [0, '', 'thick', -2, Number.POSITIVE_INFINITY]) {
            const wrapper = mountWithItems([
                { key: 'a', icon: { path: HOST_PATH, stroke_width: stroke }, tone: 'on' },
            ]);

            const svg = wrapper.find('[data-extra-cell] svg');
            expect(svg.attributes('fill')).toBe('currentColor');
            expect(svg.attributes('stroke-width')).toBeUndefined();
        }
    });

    it('degrades a malformed icon without warning in the console', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        mountWithItems([
            { key: 'number', icon: 42, text: 'B', tone: 'on' },
            { key: 'bool', icon: true, text: 'C', tone: 'on' },
        ]);

        // Quiet degradation means quiet: a malformed cell must not spam the
        // operator's console once per badge per render.
        expect(warn).not.toHaveBeenCalled();
        warn.mockRestore();
    });

    it('degrades a malformed icon shape instead of breaking the row', () => {
        const wrapper = mountWithItems([
            { key: 'nopath', icon: { view_box: '0 0 24 24' }, text: 'A', tone: 'on' },
            { key: 'number', icon: 42, text: 'B', tone: 'on' },
            { key: 'array', icon: [HOST_PATH], text: 'C', tone: 'on' },
            { key: 'empty', icon: { path: '' }, text: 'E', tone: 'on' },
        ]);

        const buttons = wrapper.findAll('[data-extra-cell] button');
        expect(buttons).toHaveLength(4);
        for (const button of buttons) {
            expect(button.find('svg').exists()).toBe(false);
        }
        expect(buttons.map((b) => b.text())).toEqual(['A', 'B', 'C', 'E']);
    });

    it('never renders host path data as markup', () => {
        const wrapper = mountWithItems([
            { key: 'evil', icon: { path: '"><script>alert(1)</script>' }, text: 'X', tone: 'on' },
        ]);

        // Assert against the real DOM, not serialised HTML: path data is bound to
        // the `d` attribute, so no element can be conjured out of it.
        expect(wrapper.element.querySelectorAll('script')).toHaveLength(0);
        // Unconditionally: if the glyph ever stopped rendering, this guard would
        // otherwise pass while checking nothing.
        expect(wrapper.find('[data-extra-cell] path').attributes('d')).toBe('"><script>alert(1)</script>');
    });
});
