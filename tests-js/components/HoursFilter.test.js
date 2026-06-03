import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

const Link = {
    props: ['href', 'preserveScroll'],
    template: '<a :href="href"><slot /></a>',
};

import HoursFilter from '../../resources/js/components/activitylog/HoursFilter.vue';

const globalMounts = {
    global: {
        stubs: { Link },
        mocks: { $t: (key) => key },
    },
};

describe('HoursFilter', () => {
    it('renders one link per available window', () => {
        const wrapper = mount(HoursFilter, {
            props: { hours: [1, 6, 24], selected: 24, baseUrl: '/admin/activity-log' },
            ...globalMounts,
        });

        const links = wrapper.findAll('a');
        expect(links).toHaveLength(3);
        expect(wrapper.text()).toContain('1');
        expect(wrapper.text()).toContain('24');
    });

    it('builds an hours query string onto the base url', () => {
        const wrapper = mount(HoursFilter, {
            props: { hours: [1, 6, 24], selected: 24, baseUrl: '/admin/activity-log' },
            ...globalMounts,
        });

        const first = wrapper.findAll('a')[0];
        expect(first.attributes('href')).toContain('/admin/activity-log');
        expect(first.attributes('href')).toContain('hours=1');
    });

    it('marks the selected window distinctly', () => {
        const wrapper = mount(HoursFilter, {
            props: { hours: [1, 6, 24], selected: 6, baseUrl: '/admin/activity-log' },
            ...globalMounts,
        });

        const selected = wrapper.findAll('a').find((a) => a.classes().includes('bg-brand-500'));
        expect(selected.text()).toContain('6');
    });
});
