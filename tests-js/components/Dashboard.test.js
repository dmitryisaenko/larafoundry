import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// AdminLayout (used by the Dashboard page) calls usePage; stub the Inertia
// runtime so the page mounts without a live Inertia app.
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { navigation: [], flash: {}, impersonating: false } }),
    router: { visit: vi.fn(), get: vi.fn(), on: vi.fn(() => vi.fn()) },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import UsersWidget from '../../resources/js/components/admin/dashboard/UsersWidget.vue';
import CompaniesWidget from '../../resources/js/components/admin/dashboard/CompaniesWidget.vue';
import ActivityWidget from '../../resources/js/components/admin/dashboard/ActivityWidget.vue';
import UnknownWidget from '../../resources/js/components/admin/dashboard/UnknownWidget.vue';
import Dashboard from '../../resources/js/Pages/Admin/Dashboard.vue';
import { dashboardWidgets, registerDashboardWidget } from '../../resources/js/index.js';

describe('UsersWidget', () => {
    it('renders the translated title and the user stats', () => {
        const data = { total: 12, new_today: 1, new_month: 4, new_year: 9, verified: 8, blocked: 2, active: 5 };
        const wrapper = mount(UsersWidget, { props: { titleKey: 'Users', data, meta: { icon: 'users' } } });

        expect(wrapper.text()).toContain('Users');
        expect(wrapper.text()).toContain('12');
        expect(wrapper.text()).toContain('Verified');
        expect(wrapper.text()).toContain('Blocked');
    });

    it('falls back to zero for a missing metric', () => {
        const wrapper = mount(UsersWidget, { props: { titleKey: 'Users', data: {}, meta: {} } });
        expect(wrapper.text()).toContain('0');
    });
});

describe('CompaniesWidget', () => {
    it('renders totals and the subscription breakdown', () => {
        const data = { total: 6, new_month: 2, on_trial: 1, active: 2, expiring: 1, expired: 1, never_activated: 1 };
        const wrapper = mount(CompaniesWidget, { props: { titleKey: 'Companies', data, meta: {} } });

        expect(wrapper.text()).toContain('Companies');
        expect(wrapper.text()).toContain('On trial');
        expect(wrapper.text()).toContain('Expiring');
        expect(wrapper.text()).toContain('Never activated');
    });
});

describe('ActivityWidget', () => {
    it('renders the 24h count and a feed row per entry', () => {
        const data = {
            last_24h: 3,
            recent: [
                { id: 1, description: 'admin.user.blocked', causer: 'Boss Man', created_at: '2026-06-05T10:00:00+00:00' },
                { id: 2, description: 'admin.company.blocked', causer: null, created_at: '2026-06-05T08:00:00+00:00' },
            ],
        };
        const wrapper = mount(ActivityWidget, { props: { titleKey: 'Recent activity', data, meta: {} } });

        expect(wrapper.text()).toContain('Last 24 hours');
        expect(wrapper.text()).toContain('admin.user.blocked');
        expect(wrapper.text()).toContain('Boss Man');
        expect(wrapper.findAll('li')).toHaveLength(2);
    });

    it('shows an empty state when there is no recent activity', () => {
        const wrapper = mount(ActivityWidget, { props: { titleKey: 'Recent activity', data: { last_24h: 0, recent: [] }, meta: {} } });
        expect(wrapper.text()).toContain('No recent activity');
    });
});

describe('UnknownWidget', () => {
    it('renders the raw data so a missing component degrades gracefully', () => {
        const wrapper = mount(UnknownWidget, { props: { titleKey: 'Revenue', data: { foo: 42 }, meta: {} } });
        expect(wrapper.text()).toContain('Revenue');
        expect(wrapper.text()).toContain('42');
    });
});

describe('dashboard widget registry', () => {
    it('ships the four core widgets', () => {
        expect(Object.keys(dashboardWidgets)).toEqual(
            expect.arrayContaining(['UsersWidget', 'CompaniesWidget', 'ActivityWidget', 'UnknownWidget']),
        );
    });

    it('registers a new widget by name', () => {
        const Dummy = { template: '<div>dummy</div>' };
        registerDashboardWidget('DummyWidget', Dummy);
        expect(dashboardWidgets.DummyWidget).toBe(Dummy);
    });
});

describe('Dashboard page', () => {
    it('resolves each widget to its registered component', () => {
        const widgets = [
            { key: 'core.users', component: 'UsersWidget', titleKey: 'Users', order: 10, data: { total: 7 }, meta: {} },
        ];
        const wrapper = mount(Dashboard, { props: { widgets } });

        expect(wrapper.text()).toContain('Users');
        expect(wrapper.text()).toContain('7');
    });

    it('falls back to UnknownWidget for a component the registry does not know', () => {
        const widgets = [
            { key: 'billing.revenue', component: 'RevenueWidget', titleKey: 'Revenue', order: 7, data: { mystery: 99 }, meta: {} },
        ];
        const wrapper = mount(Dashboard, { props: { widgets } });

        // No RevenueWidget registered → the raw data still renders, no crash.
        expect(wrapper.text()).toContain('Revenue');
        expect(wrapper.text()).toContain('99');
    });
});
