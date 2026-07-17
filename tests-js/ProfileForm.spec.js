import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, enableAutoUnmount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

// The form calls Inertia's useForm; mock the module so it mounts without a live
// Inertia app.
vi.mock('@inertiajs/vue3', () => ({
    useForm: (data = {}) => ({
        ...data,
        errors: {},
        processing: false,
        put: vi.fn(),
        reset: vi.fn(),
    }),
    usePage: () => ({ props: {} }),
}));

// Stub the leaf fields; attrs (type/name) fall through to the stub root so we can
// still assert on the rendered email input.
vi.mock('@dmitryisaenko/larafoundry', () => ({
    InputField: { name: 'InputField', inheritAttrs: true, template: '<input />' },
    SelectField: { name: 'SelectField', template: '<select />' },
    DateField: { name: 'DateField', template: '<input type="date" />' },
}));

import ProfileForm from '../resources/js/Pages/Profile/sections/ProfileForm.vue';

const profile = { name: 'A', email: 'a@x.test', email_verified: true };

describe('ProfileForm email block', () => {
    it('shows the email field for a normal (editable) profile', () => {
        const wrapper = mount(ProfileForm, { props: { profile } });
        expect(wrapper.find('input[type="email"]').exists()).toBe(true);
    });

    it('hides the email field entirely when the address is immutable (operator hub)', () => {
        const wrapper = mount(ProfileForm, { props: { profile, emailEditable: false } });
        expect(wrapper.find('input[type="email"]').exists()).toBe(false);
    });
});
