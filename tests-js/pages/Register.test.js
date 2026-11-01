import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

vi.mock('@inertiajs/vue3', () => {
    const form = reactive({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        processing: false,
        errors: {},
        post: vi.fn(),
        reset: vi.fn(),
    });
    return {
        useForm: () => form,
        usePage: () => ({ props: { flash: {}, consent: { terms_required: true } } }),
        router: { visit: vi.fn() },
        Link: {
            name: 'Link',
            props: ['href'],
            template: '<a :href="href"><slot /></a>',
        },
    };
});

import Register from '../../resources/js/Pages/Auth/Register.vue';

describe('Register page', () => {
    it('links the documents with plain anchors, which a host template can answer', () => {
        const wrapper = mount(Register);

        const inertia = wrapper.findAllComponents({ name: 'Link' }).map((link) => link.props('href'));
        expect(inertia).not.toContain('/legal/terms');
        expect(inertia).not.toContain('/legal/privacy');
        expect(wrapper.find('a[href="/legal/terms"]').attributes('rel')).toBe('noopener');
        expect(wrapper.find('a[href="/legal/privacy"]').attributes('rel')).toBe('noopener');
    });

    it('mounts without errors', () => {
        const wrapper = mount(Register);
        expect(wrapper.exists()).toBe(true);
    });

    it('renders name, email, password and confirmation fields', () => {
        const wrapper = mount(Register);
        expect(wrapper.find('input[name="name"]').exists()).toBe(true);
        expect(wrapper.find('input[name="email"]').exists()).toBe(true);
        expect(wrapper.find('input[name="password"]').exists()).toBe(true);
        expect(wrapper.find('input[name="password_confirmation"]').exists()).toBe(true);
    });

    it('submits the form via form.post to /register', async () => {
        const { useForm } = await import('@inertiajs/vue3');
        const form = useForm();

        const wrapper = mount(Register);
        await wrapper.find('form').trigger('submit.prevent');

        expect(form.post).toHaveBeenCalled();
        expect(form.post.mock.calls[0][0]).toBe('/register');
    });
});
