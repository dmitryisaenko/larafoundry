import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

// Stub Inertia's <Link> as a plain anchor so the item renders without the runtime.
const Link = {
    props: ['href'],
    template: '<a :href="href"><slot /></a>',
};

import NotificationItem from '../../resources/js/components/notifications/NotificationItem.vue';

const mountOpts = { global: { stubs: { Link } } };

const base = {
    id: 1,
    type: 'info',
    title: 'Order shipped',
    body: 'Your order is on its way',
    actions: [],
    created_human: '2 minutes ago',
    read_at: null,
};

describe('NotificationItem', () => {
    it('renders the title and body as text', () => {
        const wrapper = mount(NotificationItem, { props: { notification: base }, ...mountOpts });

        expect(wrapper.text()).toContain('Order shipped');
        expect(wrapper.text()).toContain('Your order is on its way');
    });

    it('escapes HTML in the body — never v-html (security finding #1)', () => {
        const wrapper = mount(NotificationItem, {
            props: { notification: { ...base, body: '<img src=x onerror="alert(1)">' } },
            ...mountOpts,
        });

        // Interpolated as text: no real <img> element is ever injected.
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.html()).toContain('&lt;img');
    });

    it('renders actions as plain links', () => {
        const wrapper = mount(NotificationItem, {
            props: { notification: { ...base, actions: [{ label: 'View order', url: '/orders/1' }] } },
            ...mountOpts,
        });

        const link = wrapper.find('a');
        expect(link.exists()).toBe(true);
        expect(link.attributes('href')).toBe('/orders/1');
        expect(link.text()).toBe('View order');
    });

    it('emits read while unread and hides the button once read', async () => {
        const unread = mount(NotificationItem, { props: { notification: base }, ...mountOpts });
        const button = unread.find('button');
        expect(button.exists()).toBe(true);

        await button.trigger('click');
        expect(unread.emitted('read')[0]).toEqual([1]);

        const read = mount(NotificationItem, {
            props: { notification: { ...base, read_at: '2026-01-01T00:00:00Z' } },
            ...mountOpts,
        });
        expect(read.find('button').exists()).toBe(false);
    });
});
