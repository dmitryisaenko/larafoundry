import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

// Stub Inertia's <Link> as a plain anchor so the table renders without the runtime.
const Link = {
    props: ['href'],
    template: '<a :href="href"><slot /></a>',
};

import ActivityLogTable from '../../resources/js/components/activitylog/ActivityLogTable.vue';

const globalMounts = {
    global: {
        stubs: { Link },
        mocks: { $t: (key) => key },
    },
};

const baseLog = {
    id: 1,
    description: 'User logged in',
    human_date: '2 minutes ago',
    formatted_date: '03.06.2026 10:00:00',
    user_ip: '8.8.8.8',
    causer_id: 5,
    user: { name: 'Alice' },
    response_code: 200,
    is_successful: true,
    user_device_name: 'Mac',
    user_os: 'macOS',
    user_browser: 'Safari',
    geo_country: 'Wonderland',
    geo_city: 'Heart City',
    route_name: 'dashboard',
    request_method: 'GET',
    full_url: 'http://localhost/dashboard',
    user_agent: 'Mozilla/5.0',
    properties: { event_group: 'Auth' },
};

describe('ActivityLogTable', () => {
    it('renders a row per log entry', () => {
        const wrapper = mount(ActivityLogTable, {
            props: { logs: [baseLog, { ...baseLog, id: 2, description: 'Logout' }] },
            ...globalMounts,
        });

        expect(wrapper.text()).toContain('User logged in');
        expect(wrapper.text()).toContain('Logout');
        expect(wrapper.text()).toContain('Alice');
    });

    it('shows an empty-state when there are no logs', () => {
        const wrapper = mount(ActivityLogTable, {
            props: { logs: [] },
            ...globalMounts,
        });

        expect(wrapper.text()).toContain('No activity in this window.');
    });

    it('reveals device and location details on expand', async () => {
        const wrapper = mount(ActivityLogTable, {
            props: { logs: [baseLog] },
            ...globalMounts,
        });

        // Detail row is hidden until toggled.
        expect(wrapper.text()).not.toContain('Wonderland');

        await wrapper.find('table button').trigger('click');

        expect(wrapper.text()).toContain('Wonderland');
        expect(wrapper.text()).toContain('Safari');
    });

    it('renders a user-controlled user_agent as inert text, never as markup (XSS guard)', async () => {
        const malicious = {
            ...baseLog,
            user_agent: '<img src=x onerror=alert(1)>',
            full_url: 'http://localhost/?q=<script>alert(2)</script>',
        };

        const wrapper = mount(ActivityLogTable, {
            props: { logs: [malicious] },
            ...globalMounts,
        });

        await wrapper.find('table button').trigger('click');

        // The payload must appear escaped in the HTML, and produce no live nodes.
        expect(wrapper.html()).toContain('&lt;img');
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('script').exists()).toBe(false);
    });
});
