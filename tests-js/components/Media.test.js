import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

import UserAvatar from '../../resources/js/components/media/UserAvatar.vue';
import CompanyLogo from '../../resources/js/components/media/CompanyLogo.vue';
import FileUpload from '../../resources/js/components/media/FileUpload.vue';
import ImageUpload from '../../resources/js/components/media/ImageUpload.vue';

// $t just echoes the key in tests.
const global = { mocks: { $t: (key) => key } };

describe('UserAvatar', () => {
    it('renders the image when a src is given', () => {
        const wrapper = mount(UserAvatar, {
            props: { src: 'https://cdn.test/ada.png', name: 'Ada Lovelace' },
        });

        const img = wrapper.find('img');
        expect(img.exists()).toBe(true);
        expect(img.attributes('src')).toBe('https://cdn.test/ada.png');
    });

    it('falls back to initials when no src', () => {
        const wrapper = mount(UserAvatar, { props: { src: '', name: 'Ada Lovelace' } });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.text()).toBe('AL');
    });

    it('falls back to initials when the image errors', async () => {
        const wrapper = mount(UserAvatar, {
            props: { src: 'https://dead.link/x.png', name: 'Grace Hopper' },
        });

        await wrapper.find('img').trigger('error');

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.text()).toBe('GH');
    });
});

describe('CompanyLogo', () => {
    it('renders the logo image when src is set', () => {
        const wrapper = mount(CompanyLogo, {
            props: { src: 'https://cdn.test/acme.png', name: 'Acme' },
        });

        expect(wrapper.find('img').attributes('src')).toBe('https://cdn.test/acme.png');
    });

    it('shows the company initial when there is no logo', () => {
        const wrapper = mount(CompanyLogo, { props: { src: null, name: 'Beta' } });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.text()).toBe('B');
    });
});

describe('FileUpload', () => {
    it('emits the chosen file via v-model', async () => {
        const wrapper = mount(FileUpload, { props: { label: 'File' }, global });

        const file = new File(['data'], 'doc.pdf', { type: 'application/pdf' });
        const input = wrapper.find('input[type="file"]');
        Object.defineProperty(input.element, 'files', { value: [file] });
        await input.trigger('change');

        expect(wrapper.emitted('update:modelValue')).toBeTruthy();
        expect(wrapper.emitted('update:modelValue')[0][0]).toBe(file);
    });

    it('shows a validation error when given one', () => {
        const wrapper = mount(FileUpload, { props: { error: 'Too big' }, global });

        expect(wrapper.text()).toContain('Too big');
    });
});

describe('ImageUpload', () => {
    it('shows an existing image when currentUrl is provided', () => {
        const wrapper = mount(ImageUpload, {
            props: { currentUrl: 'https://cdn.test/logo.png' },
            global,
        });

        expect(wrapper.find('img').attributes('src')).toBe('https://cdn.test/logo.png');
    });

    it('renders a FileUpload for picking a new image', () => {
        const wrapper = mount(ImageUpload, { props: { label: 'Logo' }, global });

        expect(wrapper.find('input[type="file"]').exists()).toBe(true);
    });
});
