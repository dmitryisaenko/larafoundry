import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import InputField from '../../resources/js/components/ui/InputField.vue';

describe('InputField', () => {
    it('renders the label from the title prop', () => {
        const wrapper = mount(InputField, {
            props: { title: 'Email', name: 'email' },
        });

        const label = wrapper.find('label');
        expect(label.exists()).toBe(true);
        expect(label.text()).toContain('Email');
        expect(label.attributes('for')).toBe('email');
    });

    it('shows a required asterisk only when required', () => {
        const plain = mount(InputField, { props: { title: 'Email', name: 'email' } });
        expect(plain.find('span.text-danger').exists()).toBe(false);

        const required = mount(InputField, {
            props: { title: 'Email', name: 'email', required: true },
        });
        const star = required.find('span.text-danger');
        expect(star.exists()).toBe(true);
        expect(star.text()).toBe('*');
    });

    it('emits update:modelValue on input (v-model binding)', async () => {
        const wrapper = mount(InputField, {
            props: { name: 'email', modelValue: '' },
        });

        const input = wrapper.find('input');
        await input.setValue('hello@example.com');

        expect(wrapper.emitted('update:modelValue')).toBeTruthy();
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['hello@example.com']);
    });

    it('reflects the modelValue prop onto the input', () => {
        const wrapper = mount(InputField, {
            props: { name: 'email', modelValue: 'preset@example.com' },
        });
        expect(wrapper.find('input').element.value).toBe('preset@example.com');
    });

    it('shows the error from errors[name] and applies the error class', () => {
        const wrapper = mount(InputField, {
            props: {
                name: 'email',
                errors: { email: 'The email is invalid.' },
            },
        });

        const error = wrapper.find('p.text-danger');
        expect(error.exists()).toBe(true);
        expect(error.text()).toBe('The email is invalid.');
        expect(wrapper.find('input').classes()).toContain('border-danger');
    });

    it('renders no error paragraph when errors[name] is absent', () => {
        const wrapper = mount(InputField, {
            props: { name: 'email', errors: { other: 'nope' } },
        });
        expect(wrapper.find('p.text-danger').exists()).toBe(false);
        expect(wrapper.find('input').classes()).toContain('border-border');
    });

    // Regression test for the inheritAttrs:false + v-bind="$attrs" fix: stray
    // attributes must land on the inner <input>, not the wrapper <div>.
    it('forwards fall-through attributes onto the inner input', () => {
        const wrapper = mount(InputField, {
            props: { name: 'email', type: 'email' },
            attrs: { autocomplete: 'email', inputmode: 'email' },
        });

        const input = wrapper.find('input');
        expect(input.attributes('autocomplete')).toBe('email');
        expect(input.attributes('inputmode')).toBe('email');

        // And not dumped on the root wrapper.
        expect(wrapper.element.getAttribute('autocomplete')).toBeNull();
    });

    it('passes type/placeholder/name through to the input', () => {
        const wrapper = mount(InputField, {
            props: {
                name: 'password',
                type: 'password',
                placeholder: 'secret',
            },
        });
        const input = wrapper.find('input');
        expect(input.attributes('type')).toBe('password');
        expect(input.attributes('name')).toBe('password');
        expect(input.attributes('placeholder')).toBe('secret');
    });
});
