import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import DateField from '../../resources/js/components/ui/DateField.vue';

describe('DateField', () => {
    it('mounts with a native date input and renders the label', () => {
        const wrapper = mount(DateField, {
            props: { title: 'Birthday', name: 'birthday' },
        });
        const input = wrapper.find('input');
        expect(input.exists()).toBe(true);
        expect(input.attributes('type')).toBe('date');
        expect(wrapper.find('label').text()).toContain('Birthday');
    });

    it('emits update:modelValue on input (v-model)', async () => {
        const wrapper = mount(DateField, {
            props: { name: 'birthday', modelValue: '' },
        });
        await wrapper.find('input').setValue('2026-06-02');
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['2026-06-02']);
    });

    it('shows the error from errors[name]', () => {
        const wrapper = mount(DateField, {
            props: { name: 'birthday', errors: { birthday: 'Invalid date.' } },
        });
        expect(wrapper.find('p.text-danger').text()).toBe('Invalid date.');
    });
});
