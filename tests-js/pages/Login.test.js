import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

// Mock the Inertia runtime. useForm returns a reactive form object with the
// fields the page declares plus post/reset/processing/errors. usePage is needed
// transitively by AppFlashMessage inside AppBaseLayout.
vi.mock('@inertiajs/vue3', () => {
    const form = reactive({
        email: '',
        password: '',
        remember: false,
        processing: false,
        errors: {},
        post: vi.fn(),
        reset: vi.fn(),
    });
    return {
        useForm: () => form,
        usePage: () => ({
            props: {
                flash: {},
                auth_qr: { enabled: false, poll_interval_ms: 2000 },
                auth_oauth: { enabled: true, providers: ['google', 'facebook', 'twitter'] },
            },
        }),
        router: { visit: vi.fn() },
        Link: {
            name: 'Link',
            props: ['href'],
            template: '<a :href="href"><slot /></a>',
        },
    };
});

import Login from '../../resources/js/Pages/Auth/Login.vue';

describe('Login page', () => {
    it('mounts without errors', () => {
        const wrapper = mount(Login);
        expect(wrapper.exists()).toBe(true);
    });

    it('renders email and password fields', () => {
        const wrapper = mount(Login);
        expect(wrapper.find('input[name="email"]').exists()).toBe(true);
        expect(wrapper.find('input[name="password"]').exists()).toBe(true);
        expect(wrapper.find('input[name="password"]').attributes('type')).toBe('password');
    });

    it('renders one OAuth button per configured provider, driven by the auth_oauth prop', () => {
        const wrapper = mount(Login);

        // The mocked auth_oauth prop lists google/facebook/twitter — each renders.
        expect(wrapper.find('a[href="/auth/oauth/google"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/auth/oauth/facebook"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/auth/oauth/twitter"]').exists()).toBe(true);

        // github is not in the configured list, so no button is rendered for it.
        expect(wrapper.find('a[href="/auth/oauth/github"]').exists()).toBe(false);

        // The label maps the slug to a human brand name (capitalized fallback otherwise).
        expect(wrapper.find('a[href="/auth/oauth/google"]').text()).toContain('Google');
    });

    it('submits the form via form.post to /login', async () => {
        const { useForm } = await import('@inertiajs/vue3');
        const form = useForm();

        const wrapper = mount(Login);
        await wrapper.find('form').trigger('submit.prevent');

        expect(form.post).toHaveBeenCalled();
        expect(form.post.mock.calls[0][0]).toBe('/login');
    });
});
