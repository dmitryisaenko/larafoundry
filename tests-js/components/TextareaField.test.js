import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import TextareaField from '../../resources/js/components/ui/TextareaField.vue';

describe('TextareaField', () => {
    it('mounts and renders the label', () => {
        const wrapper = mount(TextareaField, {
            props: { title: 'Bio', name: 'bio' },
        });
        expect(wrapper.find('textarea').exists()).toBe(true);
        expect(wrapper.find('label').text()).toContain('Bio');
    });

    it('emits update:modelValue on input (v-model)', async () => {
        const wrapper = mount(TextareaField, {
            props: { name: 'bio', modelValue: '' },
        });
        await wrapper.find('textarea').setValue('hello world');
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['hello world']);
    });

    it('shows the error from errors[name]', () => {
        const wrapper = mount(TextareaField, {
            props: { name: 'bio', errors: { bio: 'Too long.' } },
        });
        expect(wrapper.find('p.text-danger').text()).toBe('Too long.');
    });
});
