import { describe, it, expect, afterEach } from 'vitest';
import { mount, enableAutoUnmount } from '@vue/test-utils';

import LogoutForm from '../resources/js/components/LogoutForm.vue';

enableAutoUnmount(afterEach);

/**
 * LogoutForm reads the CSRF token from `<meta name="csrf-token">` at mount, so
 * each case sets (or omits) the meta before mounting and cleans it up after.
 */
function setCsrfMeta(value) {
    const meta = document.createElement('meta');
    meta.setAttribute('name', 'csrf-token');
    meta.setAttribute('content', value);
    document.head.appendChild(meta);
    return meta;
}

afterEach(() => {
    document.head.querySelectorAll('meta[name="csrf-token"]').forEach((m) => m.remove());
});

describe('LogoutForm', () => {
    it('renders a native form that POSTs to /logout', () => {
        const wrapper = mount(LogoutForm);
        const form = wrapper.find('form');

        expect(form.exists()).toBe(true);
        expect(form.attributes('method')).toBe('POST');
        expect(form.attributes('action')).toBe('/logout');
        expect(wrapper.find('button').attributes('type')).toBe('submit');
    });

    it('injects the CSRF token from the meta tag into a hidden _token field', () => {
        setCsrfMeta('test-token-123');

        const wrapper = mount(LogoutForm);
        const token = wrapper.find('input[type="hidden"]');

        expect(token.attributes('name')).toBe('_token');
        expect(token.element.value).toBe('test-token-123');
    });

    it('falls back to an empty token when the meta tag is absent', () => {
        const wrapper = mount(LogoutForm);

        expect(wrapper.find('input[name="_token"]').element.value).toBe('');
    });

    it('applies buttonClass to the submit button and renders the default slot inside it', () => {
        const wrapper = mount(LogoutForm, {
            props: { buttonClass: 'my-btn-class' },
            slots: { default: '<span class="slot-content">Log out</span>' },
        });
        const button = wrapper.find('button');

        expect(button.classes()).toContain('my-btn-class');
        expect(button.find('.slot-content').text()).toBe('Log out');
    });
});
