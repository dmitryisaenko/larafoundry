import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// SelectField calls useT() -> vue-i18n's useI18n(). Mock the module so the
// component mounts without a real i18n plugin; t() echoes its key.
vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key) => key }),
}));

import SelectField from '../../resources/js/components/ui/SelectField.vue';

describe('SelectField', () => {
    it('mounts and renders the label', () => {
        const wrapper = mount(SelectField, {
            props: { title: 'Country', name: 'country' },
        });
        expect(wrapper.find('select').exists()).toBe(true);
        expect(wrapper.find('label').text()).toContain('Country');
    });

    it('renders options from valueNameArray', () => {
        const wrapper = mount(SelectField, {
            props: {
                name: 'country',
                valueNameArray: [
                    { value: 'us', name: 'United States' },
                    { value: 'ua', name: 'Ukraine' },
                ],
            },
        });
        const options = wrapper.findAll('option');
        expect(options).toHaveLength(2);
        expect(options[0].text()).toBe('United States');
        expect(options[0].attributes('value')).toBe('us');
    });

    it('emits update:modelValue when an option is selected (v-model)', async () => {
        const wrapper = mount(SelectField, {
            props: {
                name: 'country',
                modelValue: '',
                valueNameArray: [
                    { value: 'us', name: 'United States' },
                    { value: 'ua', name: 'Ukraine' },
                ],
            },
        });
        await wrapper.find('select').setValue('ua');
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['ua']);
    });

    it('shows the error from errors[name]', () => {
        const wrapper = mount(SelectField, {
            props: { name: 'country', errors: { country: 'Required.' } },
        });
        expect(wrapper.find('p.text-danger').text()).toBe('Required.');
    });
});
