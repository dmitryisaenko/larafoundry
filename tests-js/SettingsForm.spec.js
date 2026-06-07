import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, enableAutoUnmount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

vi.mock('@inertiajs/vue3', () => ({
    router: { put: vi.fn() },
}));

// Stub the core leaf inputs so the spec stays on SettingsForm's own logic.
vi.mock('@dmitryisaenko/larafoundry', () => ({
    InputField: { name: 'InputField', props: ['modelValue'], template: '<input />' },
    SelectField: {
        name: 'SelectField',
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template: '<select @change="$emit(\'update:modelValue\', $event.target.value)"><option value="light">light</option><option value="dark">dark</option></select>',
    },
}));

import SettingsForm from '../resources/js/components/settings/SettingsForm.vue';
import { router } from '@inertiajs/vue3';

describe('SettingsForm', () => {
    beforeEach(() => router.put.mockClear());

    it('shows an empty-state when there are no settings', () => {
        const wrapper = mount(SettingsForm, { props: { schema: [], values: {}, endpoint: '/x' } });
        expect(wrapper.text()).toContain('There are no settings to manage here yet.');
    });

    it('saves a boolean preference on toggle', async () => {
        const wrapper = mount(SettingsForm, {
            props: {
                schema: [{ key: 'email_notifications', label: 'Email', type: 'boolean', options: [] }],
                values: { email_notifications: false },
                endpoint: '/settings/account',
            },
        });

        await wrapper.find('input[type="checkbox"]').setValue(true);

        expect(router.put).toHaveBeenCalledWith(
            '/settings/account',
            { key: 'email_notifications', value: true },
            { preserveScroll: true, preserveState: true },
        );
    });

    it('saves an enum preference on change', async () => {
        const wrapper = mount(SettingsForm, {
            props: {
                schema: [{ key: 'theme', label: 'Theme', type: 'string', options: ['light', 'dark'] }],
                values: { theme: 'light' },
                endpoint: '/settings/account',
            },
        });

        const select = wrapper.find('select');
        select.element.value = 'dark';
        await select.trigger('change');

        expect(router.put).toHaveBeenCalledWith(
            '/settings/account',
            { key: 'theme', value: 'dark' },
            { preserveScroll: true, preserveState: true },
        );
    });

    it('disables controls when editing is not allowed', () => {
        const wrapper = mount(SettingsForm, {
            props: {
                schema: [{ key: 'email_notifications', label: 'Email', type: 'boolean', options: [] }],
                values: { email_notifications: true },
                endpoint: '/settings/company',
                canEdit: false,
            },
        });

        expect(wrapper.find('input[type="checkbox"]').attributes('disabled')).toBeDefined();
    });
});
