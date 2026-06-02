import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// Drive the paginator from the Inertia `pagination` shared prop. Link is stubbed
// to a plain anchor so we can assert hrefs without the Inertia runtime.
const page = { props: { pagination: {} } };

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
}));

import PagePaginator from '../../resources/js/components/PagePaginator.vue';

describe('PagePaginator', () => {
    it('renders nothing when total is 0', () => {
        page.props.pagination = { total: 0, current_page: 1, last_page: 1, from: 0, to: 0 };
        const wrapper = mount(PagePaginator);
        expect(wrapper.find('nav').exists()).toBe(false);
    });

    it('renders page links and a range summary for a multi-page set', () => {
        page.props.pagination = {
            total: 50,
            current_page: 1,
            last_page: 3,
            per_page: 25,
            from: 1,
            to: 25,
        };
        const wrapper = mount(PagePaginator);

        expect(wrapper.find('nav').exists()).toBe(true);
        // Range summary "1–25 / 50".
        expect(wrapper.text()).toContain('1');
        expect(wrapper.text()).toContain('50');

        // Pages 2 and 3 become links (current page 1 is a non-link span).
        const links = wrapper.findAll('a');
        const hrefs = links.map((l) => l.attributes('href'));
        expect(hrefs.some((h) => h.includes('page=2'))).toBe(true);
        expect(hrefs.some((h) => h.includes('page=3'))).toBe(true);
    });

    it('marks the current page as a non-link span', () => {
        page.props.pagination = {
            total: 50,
            current_page: 2,
            last_page: 3,
            per_page: 25,
            from: 26,
            to: 50,
        };
        const wrapper = mount(PagePaginator);

        // The current page (2) is rendered with the active brand background span,
        // not an anchor.
        const activeSpan = wrapper.find('span.bg-brand-500');
        expect(activeSpan.exists()).toBe(true);
        expect(activeSpan.text()).toBe('2');
    });
});
