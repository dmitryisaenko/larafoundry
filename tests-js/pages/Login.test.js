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
        usePage: () => ({ props: { flash: {} } }),
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

    it('renders OAuth buttons including the google provider href', () => {
        const wrapper = mount(Login);
        const googleLink = wrapper.find('a[href="/auth/oauth/google"]');
        expect(googleLink.exists()).toBe(true);

        const githubLink = wrapper.find('a[href="/auth/oauth/github"]');
        expect(githubLink.exists()).toBe(true);
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
