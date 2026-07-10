import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, enableAutoUnmount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

// The form drives an Inertia useForm; mock the module so it mounts standalone.
vi.mock('@inertiajs/vue3', () => ({
    useForm: (data = {}) => ({
        ...data,
        errors: {},
        processing: false,
        post: vi.fn(),
    }),
}));

// Stub the barrel components to minimal, name-carrying elements so the spec can
// assert which fields render under a given userColumns set.
vi.mock('@dmitryisaenko/larafoundry', () => ({
    AdminLayout: { name: 'AdminLayout', template: '<div><slot /></div>' },
    InputField: {
        name: 'InputField',
        props: ['modelValue', 'name', 'type', 'title'],
        template: '<input :name="name" :type="type" />',
    },
    SelectField: {
        name: 'SelectField',
        props: ['modelValue', 'name', 'title'],
        template: '<select :name="name"></select>',
    },
    SocialLinksField: {
        name: 'SocialLinksField',
        props: ['modelValue', 'platforms', 'errors'],
        template: '<div class="social-widget"></div>',
    },
}));

import Create from '../resources/js/Pages/Admin/Users/Create.vue';

const globalMounts = { global: { mocks: { $t: (key) => key } } };

function mountCreate(userColumns = []) {
    return mount(Create, {
        props: { userColumns, socialPlatforms: ['website', 'github'] },
        ...globalMounts,
    });
}

describe('Admin Create user form — gated fields', () => {
    it('always shows middlename and the password confirmation', () => {
        const wrapper = mountCreate([]);
        expect(wrapper.find('input[name="middlename"]').exists()).toBe(true);
        expect(wrapper.find('input[name="password_confirmation"]').exists()).toBe(true);
    });

    it('hides sex/birth_date/social when no tokens are opted in', () => {
        const wrapper = mountCreate([]);
        expect(wrapper.find('select[name="sex"]').exists()).toBe(false);
        expect(wrapper.find('input[name="birth_date"]').exists()).toBe(false);
        expect(wrapper.find('.social-widget').exists()).toBe(false);
    });

    it('shows the sex select only under the sex token', () => {
        expect(mountCreate(['sex']).find('select[name="sex"]').exists()).toBe(true);
        expect(mountCreate([]).find('select[name="sex"]').exists()).toBe(false);
    });

    it('shows the birth_date field only under the age token', () => {
        expect(mountCreate(['age']).find('input[name="birth_date"]').exists()).toBe(true);
        expect(mountCreate([]).find('input[name="birth_date"]').exists()).toBe(false);
    });

    it('shows the social widget only under the social token', () => {
        expect(mountCreate(['social']).find('.social-widget').exists()).toBe(true);
        expect(mountCreate([]).find('.social-widget').exists()).toBe(false);
    });
});
