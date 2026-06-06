import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const Link = {
    props: ['href'],
    template: '<a :href="href"><slot /></a>',
};

import NotificationBell from '../../resources/js/components/notifications/NotificationBell.vue';

const mountOpts = { global: { stubs: { Link } } };

function jsonResponse(body) {
    return Promise.resolve({ ok: true, json: () => Promise.resolve(body) });
}

beforeEach(() => {
    // Ziggy's route() is a runtime global; stub it to a readable URL.
    global.route = (name, param) => (param !== undefined ? `/${name}/${param}` : `/${name}`);
});

afterEach(() => {
    vi.restoreAllMocks();
    delete global.route;
});

describe('NotificationBell', () => {
    it('shows the unread badge from the poll', async () => {
        global.fetch = vi.fn(() => jsonResponse({ unread_count: 3 }));

        const wrapper = mount(NotificationBell, mountOpts);
        await flushPromises();

        expect(wrapper.text()).toContain('3');
    });

    it('caps the badge at 9+', async () => {
        global.fetch = vi.fn(() => jsonResponse({ unread_count: 42 }));

        const wrapper = mount(NotificationBell, mountOpts);
        await flushPromises();

        expect(wrapper.text()).toContain('9+');
    });

    it('loads recent notifications when opened', async () => {
        global.fetch = vi.fn((url) => {
            if (String(url).includes('recent')) {
                return jsonResponse({
                    notifications: [{ id: 1, type: 'info', title: 'Hello there', body: '', actions: [], created_human: 'now', read_at: null }],
                    unread_count: 1,
                });
            }

            return jsonResponse({ unread_count: 1 });
        });

        const wrapper = mount(NotificationBell, mountOpts);
        await flushPromises();

        await wrapper.find('button').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Hello there');
    });

    it('shows an empty state when there are no notifications', async () => {
        global.fetch = vi.fn((url) => {
            if (String(url).includes('recent')) {
                return jsonResponse({ notifications: [], unread_count: 0 });
            }

            return jsonResponse({ unread_count: 0 });
        });

        const wrapper = mount(NotificationBell, mountOpts);
        await flushPromises();

        await wrapper.find('button').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('No notifications yet');
    });
});
