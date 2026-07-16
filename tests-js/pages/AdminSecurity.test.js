import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

// Inertia runtime mock. One shared reactive form stands in for every useForm()
// call the page makes (2FA confirm + password); router carries the 2FA mutations.
const post = vi.fn();
const del = vi.fn();
const form = reactive({
    code: '',
    current_password: '',
    password: '',
    password_confirmation: '',
    pin: '',
    processing: false,
    errors: {},
    post: vi.fn(),
    put: vi.fn(),
    reset: vi.fn(),
});

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: (...args) => post(...args),
        delete: (...args) => del(...args),
    },
    useForm: () => form,
    usePage: () => ({ props: { flash: {} } }),
    Link: { name: 'Link', props: ['href', 'method', 'as'], template: '<a :href="href"><slot /></a>' },
}));

// The page translates confirm-dialog copy through useT() (vue-i18n) in script.
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key) => key }) }));

import AdminSecurity from '../../resources/js/Pages/Admin/Security/Index.vue';

// Stub AdminLayout to a passthrough — the page's own sections are what we assert,
// not the whole console shell.
const mounts = {
    global: {
        stubs: {
            AdminLayout: { template: '<div><slot /></div>' },
        },
    },
};

describe('Admin/Security page', () => {
    beforeEach(() => {
        post.mockClear();
        del.mockClear();
        form.post.mockClear();
        form.put.mockClear();
    });

    it('renders the three security sections', () => {
        const wrapper = mount(AdminSecurity, mounts);
        const text = wrapper.text();
        expect(text).toContain('Two-factor authentication');
        expect(text).toContain('PIN lock'); // from the reused PinManager section
        expect(text).toContain('Change password');
    });

    it('enables two-factor through the admin proxy endpoint', async () => {
        const wrapper = mount(AdminSecurity, mounts);
        const enableBtn = wrapper.findAll('button').find((b) => b.text() === 'Enable');
        await enableBtn.trigger('click');

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/admin/security/two-factor/enable');
    });

    it('confirms enrolment with the confirmTwoFactorAuthentication error bag', async () => {
        const wrapper = mount(AdminSecurity, {
            ...mounts,
            props: {
                two_factor_setup: { svg: '<svg></svg>', recovery_codes: ['AAAA-BBBB'] },
            },
        });
        await wrapper.find('form').trigger('submit.prevent');

        expect(form.post).toHaveBeenCalledTimes(1);
        const [url, options] = form.post.mock.calls[0];
        expect(url).toBe('/admin/security/two-factor/confirm');
        expect(options.errorBag).toBe('confirmTwoFactorAuthentication');
    });

    it('submits a password change to the admin proxy endpoint with the updatePassword bag', async () => {
        const wrapper = mount(AdminSecurity, mounts);
        await wrapper.find('form.max-w-xl').trigger('submit.prevent');

        expect(form.put).toHaveBeenCalledTimes(1);
        const [url, options] = form.put.mock.calls[0];
        expect(url).toBe('/admin/security/password');
        expect(options.errorBag).toBe('updatePassword');
    });

    it('hides destructive 2FA actions from a non-stepped-up session', () => {
        const wrapper = mount(AdminSecurity, {
            ...mounts,
            props: { two_factor_enabled: true, can_manage_two_factor: false },
        });
        const labels = wrapper.findAll('button').map((b) => b.text());
        expect(labels).not.toContain('Disable');
        expect(labels).not.toContain('Regenerate recovery codes');
    });
});
