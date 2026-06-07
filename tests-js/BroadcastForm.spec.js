import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, enableAutoUnmount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

// Minimal useForm stub exposing the bound fields + the chainable submit methods.
vi.mock('@inertiajs/vue3', () => ({
    useForm: (initial) => ({
        ...initial,
        errors: {},
        processing: false,
        put: vi.fn(),
        post: vi.fn(),
        reset: vi.fn(),
        transform() {
            return this;
        },
    }),
}));

import BroadcastForm from '../resources/js/components/notifications/BroadcastForm.vue';

describe('BroadcastForm', () => {
    it('offers an opt-in "also send by email" checkbox, off by default', () => {
        const wrapper = mount(BroadcastForm, { props: { types: ['info'], availableLocales: ['en'] } });
        const checkbox = wrapper.find('input[type="checkbox"]');

        expect(checkbox.exists()).toBe(true);
        expect(checkbox.element.checked).toBe(false);
        expect(wrapper.text()).toContain('Also send by email');
    });

    it('reflects an existing broadcast that opted into email', () => {
        const wrapper = mount(BroadcastForm, {
            props: {
                notification: { id: 1, code: 'info', send_mail: true, title_translations: { en: 'Hi' } },
                types: ['info'],
                availableLocales: ['en'],
            },
        });

        expect(wrapper.find('input[type="checkbox"]').element.checked).toBe(true);
    });
});
