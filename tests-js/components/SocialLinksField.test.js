import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// SocialLinksField calls useT() -> vue-i18n's useI18n(). Mock so it mounts
// without a real i18n plugin; t() echoes its key.
vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key) => key }),
}));

import SocialLinksField from '../../resources/js/components/admin/SocialLinksField.vue';

const platforms = ['website', 'twitter', 'github'];

const globalMounts = { global: { mocks: { $t: (key) => key } } };

describe('SocialLinksField', () => {
    it('renders one row per link with a platform select and a url input', () => {
        const wrapper = mount(SocialLinksField, {
            props: {
                modelValue: [
                    { platform: 'github', url: 'https://github.com/a' },
                    { platform: 'website', url: 'https://a.test' },
                ],
                platforms,
            },
            ...globalMounts,
        });

        expect(wrapper.findAll('select')).toHaveLength(2);
        const urls = wrapper.findAll('input[type="url"]');
        expect(urls).toHaveLength(2);
        expect(urls[0].element.value).toBe('https://github.com/a');
    });

    it('appends an empty row on Add link (v-model)', async () => {
        const wrapper = mount(SocialLinksField, {
            props: { modelValue: [], platforms },
            ...globalMounts,
        });

        const addBtn = wrapper.findAll('button').find((b) => b.text() === 'Add link');
        await addBtn.trigger('click');

        const emitted = wrapper.emitted('update:modelValue').at(-1)[0];
        expect(emitted).toEqual([{ platform: 'website', url: '' }]);
    });

    it('removes a row on Remove (v-model)', async () => {
        const wrapper = mount(SocialLinksField, {
            props: {
                modelValue: [
                    { platform: 'github', url: 'https://github.com/a' },
                    { platform: 'website', url: 'https://a.test' },
                ],
                platforms,
            },
            ...globalMounts,
        });

        const removeBtn = wrapper.findAll('button').find((b) => b.text() === 'Remove');
        await removeBtn.trigger('click');

        const emitted = wrapper.emitted('update:modelValue').at(-1)[0];
        expect(emitted).toEqual([{ platform: 'website', url: 'https://a.test' }]);
    });

    it('emits the edited url on input (v-model)', async () => {
        const wrapper = mount(SocialLinksField, {
            props: {
                modelValue: [{ platform: 'github', url: '' }],
                platforms,
            },
            ...globalMounts,
        });

        const input = wrapper.find('input[type="url"]');
        await input.setValue('https://github.com/edited');

        const emitted = wrapper.emitted('update:modelValue').at(-1)[0];
        expect(emitted).toEqual([{ platform: 'github', url: 'https://github.com/edited' }]);
    });

    it('surfaces a field-level url error keyed by index', () => {
        const wrapper = mount(SocialLinksField, {
            props: {
                modelValue: [{ platform: 'website', url: 'javascript:alert(1)' }],
                platforms,
                errors: { 'social_links.0.url': 'The url must start with http:// or https://.' },
            },
            ...globalMounts,
        });

        expect(wrapper.find('p.text-danger').text()).toContain('http://');
    });
});
