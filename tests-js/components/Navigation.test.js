import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

// Inertia <Link> stub — renders an anchor with the href.
const Link = {
    props: ['href', 'ariaCurrent'],
    template: '<a :href="href"><slot /></a>',
};

import NavItem from '../../resources/js/components/navigation/NavItem.vue';
import NavIcon from '../../resources/js/components/navigation/NavIcon.vue';
import SidebarNav from '../../resources/js/components/navigation/SidebarNav.vue';

const globalMounts = {
    global: {
        stubs: { Link },
        mocks: { $t: (key) => key },
    },
};

describe('NavItem', () => {
    it('translates the label key and links to the url', () => {
        const wrapper = mount(NavItem, {
            props: { item: { labelKey: 'Users', url: '/admin/users', icon: 'users', active: false } },
            ...globalMounts,
        });

        expect(wrapper.text()).toContain('Users');
        expect(wrapper.find('a').attributes('href')).toBe('/admin/users');
    });

    it('marks the active item', () => {
        const wrapper = mount(NavItem, {
            props: { item: { labelKey: 'Users', url: '/admin/users', active: true } },
            ...globalMounts,
        });

        expect(wrapper.find('a').classes()).toContain('text-brand-700');
    });

    it('hides the label and exposes a tooltip in collapsed (icon-rail) mode', () => {
        const wrapper = mount(NavItem, {
            props: { item: { labelKey: 'Users', url: '/admin/users', icon: 'users', active: false }, collapsed: true },
            ...globalMounts,
        });

        // The text label is gone (icon only) but the accessible tooltip remains.
        expect(wrapper.text()).not.toContain('Users');
        expect(wrapper.find('a').attributes('title')).toBe('Users');
        expect(wrapper.find('svg').exists()).toBe(true);
    });
});

describe('NavIcon', () => {
    it('renders a known icon as an svg', () => {
        const wrapper = mount(NavIcon, { props: { name: 'users' }, ...globalMounts });
        expect(wrapper.find('svg').exists()).toBe(true);
    });

    it('falls back to a dot for an unknown icon', () => {
        const wrapper = mount(NavIcon, { props: { name: 'nope-not-a-real-icon' }, ...globalMounts });
        expect(wrapper.find('svg').exists()).toBe(false);
        expect(wrapper.find('span').exists()).toBe(true);
    });
});

describe('SidebarNav', () => {
    it('renders one link per leaf item', () => {
        const wrapper = mount(SidebarNav, {
            props: {
                items: [
                    { labelKey: 'Users', url: '/admin/users', active: true },
                    { labelKey: 'Activity log', url: '/admin/activity-log', active: false },
                ],
            },
            ...globalMounts,
        });

        expect(wrapper.findAll('a')).toHaveLength(2);
        expect(wrapper.text()).toContain('Users');
        expect(wrapper.text()).toContain('Activity log');
    });

    it('renders a group with its children', () => {
        const wrapper = mount(SidebarNav, {
            props: {
                items: [
                    {
                        labelKey: 'Group',
                        submenu: [
                            { labelKey: 'Child', url: '/child', active: false },
                        ],
                    },
                ],
            },
            ...globalMounts,
        });

        expect(wrapper.text()).toContain('Group');
        expect(wrapper.text()).toContain('Child');
        expect(wrapper.find('a').attributes('href')).toBe('/child');
    });

    it('passes collapsed down to leaves (icon-rail: no labels, tooltips instead)', () => {
        const wrapper = mount(SidebarNav, {
            props: {
                collapsed: true,
                items: [
                    { labelKey: 'Users', url: '/admin/users', icon: 'users', active: true },
                    { labelKey: 'Activity log', url: '/admin/activity-log', icon: 'activity', active: false },
                ],
            },
            ...globalMounts,
        });

        expect(wrapper.text()).not.toContain('Users');
        const links = wrapper.findAll('a');
        expect(links).toHaveLength(2);
        expect(links[0].attributes('title')).toBe('Users');
    });

    it('flattens a group to its leaves in collapsed mode (no expandable header)', () => {
        const wrapper = mount(SidebarNav, {
            props: {
                collapsed: true,
                items: [
                    {
                        labelKey: 'Group',
                        icon: 'settings',
                        submenu: [
                            { labelKey: 'Child', url: '/child', icon: 'users', active: false },
                        ],
                    },
                ],
            },
            ...globalMounts,
        });

        // No toggle button (the header cannot expand in a rail); the child renders
        // directly as an icon link with its label as the tooltip.
        expect(wrapper.find('button').exists()).toBe(false);
        const link = wrapper.find('a');
        expect(link.attributes('href')).toBe('/child');
        expect(link.attributes('title')).toBe('Child');
    });
});
