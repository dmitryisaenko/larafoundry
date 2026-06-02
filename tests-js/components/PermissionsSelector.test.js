import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

// useT just echoes the key in tests.
import { vi } from 'vitest';
vi.mock('../../resources/js/composables/useT.js', () => ({
    useT: () => (key) => key,
}));

import PermissionsSelector from '../../resources/js/components/PermissionsSelector.vue';

const modules = {
    profile: {
        label: 'Profile',
        permissions: {
            'profile.view': 'View own profile',
            'profile.edit': 'Edit own profile',
        },
    },
    company: {
        label: 'Company',
        permissions: {
            'company.settings.view': 'View settings',
        },
    },
};

describe('PermissionsSelector', () => {
    it('renders every catalog permission as a checkbox', () => {
        const wrapper = mount(PermissionsSelector, {
            props: { modules, modelValue: [] },
        });

        // 3 permissions + 2 module toggles
        expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(5);
        expect(wrapper.text()).toContain('profile.view');
    });

    it('reflects the selected slugs from the model', () => {
        const wrapper = mount(PermissionsSelector, {
            props: { modules, modelValue: ['profile.view'] },
        });

        const checked = wrapper.findAll('input:checked');
        // profile.view is checked (its module toggle is not, since edit is unchecked)
        expect(checked.length).toBe(1);
    });

    it('emits an updated slug list when a permission is toggled', async () => {
        const wrapper = mount(PermissionsSelector, {
            props: { modules, modelValue: [] },
        });

        // The last checkbox is company.settings.view.
        const boxes = wrapper.findAll('input[type="checkbox"]');
        await boxes[boxes.length - 1].setValue(true);

        const emitted = wrapper.emitted('update:modelValue');
        expect(emitted).toBeTruthy();
        expect(emitted[0][0]).toContain('company.settings.view');
    });

    it('toggles a whole module with its group checkbox', async () => {
        const wrapper = mount(PermissionsSelector, {
            props: { modules, modelValue: [] },
        });

        // First checkbox is the Profile module toggle (a <legend> input).
        const groupToggle = wrapper.find('legend input[type="checkbox"]');
        await groupToggle.setValue(true);

        const emitted = wrapper.emitted('update:modelValue');
        expect(emitted[0][0]).toEqual(expect.arrayContaining(['profile.view', 'profile.edit']));
    });
});
