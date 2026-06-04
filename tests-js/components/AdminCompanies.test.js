import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

import CompaniesTable from '../../resources/js/components/admin/CompaniesTable.vue';
import SubscriptionStatusBadge from '../../resources/js/components/admin/SubscriptionStatusBadge.vue';

const globalMounts = { global: { mocks: { $t: (key) => key } } };

const companies = [
    {
        uuid: 'u-1', name: 'Acme', slug: 'acme', country: 'PL', logo_url: null,
        owner: { id: 1, name: 'Joe', lastname: 'Doe', email: 'joe@x.test' },
        employees_count: 3, is_blocked: false, created_date: '01.01.2026',
        subscription: { status: 'active', has_access: true },
    },
    {
        uuid: 'u-2', name: 'Beta', slug: 'beta', country: 'UA', logo_url: null,
        owner: { id: 2, name: 'Ann', lastname: 'Lee', email: 'ann@x.test' },
        employees_count: 1, is_blocked: true, created_date: '02.01.2026',
        subscription: { status: 'never_activated', has_access: true },
    },
];

describe('CompaniesTable', () => {
    it('renders a row per company', () => {
        const wrapper = mount(CompaniesTable, { props: { companies }, ...globalMounts });
        expect(wrapper.findAll('tbody tr')).toHaveLength(2);
        expect(wrapper.text()).toContain('Acme');
        expect(wrapper.text()).toContain('joe@x.test');
    });

    it('shows an empty state when there are no companies', () => {
        const wrapper = mount(CompaniesTable, { props: { companies: [] }, ...globalMounts });
        expect(wrapper.text()).toContain('No companies found');
    });

    it('offers block for an active company and unblock for a blocked one', () => {
        const wrapper = mount(CompaniesTable, { props: { companies }, ...globalMounts });
        const labels = wrapper.findAll('button').map((b) => b.text());
        expect(labels).toContain('Block');
        expect(labels).toContain('Unblock');
    });

    it('emits view for a row', async () => {
        const wrapper = mount(CompaniesTable, { props: { companies }, ...globalMounts });
        const viewBtn = wrapper.findAll('button').find((b) => b.text() === 'View');
        await viewBtn.trigger('click');
        expect(wrapper.emitted('view')).toBeTruthy();
    });

    it('never renders a payment sum (the core stores no payments)', () => {
        const wrapper = mount(CompaniesTable, { props: { companies }, ...globalMounts });
        // headers cover identity/subscription/status, never money.
        const headers = wrapper.findAll('thead th').map((h) => h.text());
        expect(headers).not.toContain('Revenue');
        expect(headers).not.toContain('Payments');
    });
});

describe('SubscriptionStatusBadge', () => {
    it('renders the translated label for a known status', () => {
        const wrapper = mount(SubscriptionStatusBadge, { props: { status: 'on_trial' }, ...globalMounts });
        expect(wrapper.text()).toBe('On trial');
    });

    it('falls back to never_activated for an unknown status', () => {
        const wrapper = mount(SubscriptionStatusBadge, { props: { status: 'bogus' }, ...globalMounts });
        expect(wrapper.text()).toBe('Never activated');
    });
});
