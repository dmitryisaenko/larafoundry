import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// Mock the Inertia router so we can assert the switch PUT without the runtime.
const put = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    router: { put: (...args) => put(...args) },
}));

// useT just echoes the key in tests.
vi.mock('../../resources/js/composables/useT.js', () => ({
    useT: () => (key) => key,
}));

import CompanySwitcher from '../../resources/js/components/CompanySwitcher.vue';

const companies = [
    { uuid: 'a-uuid', name: 'Acme', is_owner: true },
    { uuid: 'b-uuid', name: 'Beta', is_owner: false },
];

describe('CompanySwitcher', () => {
    it('shows the active company name on the trigger', () => {
        const wrapper = mount(CompanySwitcher, {
            props: { companies, active: companies[0] },
        });

        expect(wrapper.text()).toContain('Acme');
    });

    it('lists companies when opened and switches to a different one', async () => {
        put.mockClear();
        const wrapper = mount(CompanySwitcher, {
            props: { companies, active: companies[0] },
        });

        await wrapper.find('button').trigger('click'); // open dropdown

        const items = wrapper.findAll('li button');
        expect(items).toHaveLength(2);

        // Switch to Beta (the non-active one).
        await items[1].trigger('click');

        expect(put).toHaveBeenCalledTimes(1);
        expect(put.mock.calls[0][0]).toBe('/companies/b-uuid/switch');
    });

    it('does not switch when the active company is reselected', async () => {
        put.mockClear();
        const wrapper = mount(CompanySwitcher, {
            props: { companies, active: companies[0] },
        });

        await wrapper.find('button').trigger('click');
        const items = wrapper.findAll('li button');
        await items[0].trigger('click'); // Acme = already active

        expect(put).not.toHaveBeenCalled();
    });

    it('falls back to a placeholder when no company is active', () => {
        const wrapper = mount(CompanySwitcher, {
            props: { companies, active: null },
        });

        expect(wrapper.text()).toContain('No company');
    });
});
