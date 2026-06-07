import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, enableAutoUnmount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

// The section drives an Inertia form; mock the module so it mounts without a
// live Inertia app.
vi.mock('@inertiajs/vue3', () => ({
    useForm: (data = {}) => ({
        ...data,
        errors: {},
        processing: false,
        delete: vi.fn(),
        reset: vi.fn(),
    }),
}));

// Stub InputField to a real input carrying its name, so the spec can assert
// which confirmation field is rendered.
vi.mock('@dmitryisaenko/larafoundry', () => ({
    InputField: {
        name: 'InputField',
        props: ['modelValue', 'name', 'type', 'title', 'errors', 'autocomplete'],
        template: '<input :name="name" :type="type" />',
    },
}));

import DangerZone from '../resources/js/Pages/Profile/sections/DangerZone.vue';

function mountZone(props = {}) {
    return mount(DangerZone, {
        props: { canDelete: true, oauthOnly: false, ...props },
    });
}

describe('DangerZone', () => {
    it('hides the delete control when the user still owns a company', () => {
        const wrapper = mountZone({ canDelete: false });
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('asks a password account for its current password', async () => {
        const wrapper = mountZone({ oauthOnly: false });
        await wrapper.find('button').trigger('click');

        expect(wrapper.find('input[name="current_password"]').exists()).toBe(true);
        expect(wrapper.find('input[name="email"]').exists()).toBe(false);
        expect(wrapper.find('input[name="confirm_deletion"]').exists()).toBe(false);
    });

    it('asks an OAuth-only account for its email and a confirmation checkbox', async () => {
        const wrapper = mountZone({ oauthOnly: true });
        await wrapper.find('button').trigger('click');

        expect(wrapper.find('input[name="email"]').exists()).toBe(true);
        expect(wrapper.find('input[name="confirm_deletion"]').exists()).toBe(true);
        expect(wrapper.find('input[name="current_password"]').exists()).toBe(false);
    });

    it('renders the export slot below the deletion control', () => {
        const wrapper = mount(DangerZone, {
            props: { canDelete: true, oauthOnly: false },
            slots: { default: '<div class="export-marker" />' },
        });
        expect(wrapper.find('.export-marker').exists()).toBe(true);
    });
});
