import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, enableAutoUnmount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

// Minimal useForm stub: a reactive-enough object exposing the fields the editor
// binds plus the chainable methods it calls.
vi.mock('@inertiajs/vue3', () => ({
    useForm: (initial) => ({
        ...initial,
        errors: {},
        processing: false,
        put: vi.fn(),
        reset: vi.fn(),
        transform() {
            return this;
        },
    }),
}));

vi.mock('@dmitryisaenko/larafoundry', () => ({
    InputField: { name: 'InputField', props: ['modelValue', 'name', 'title', 'errors'], template: '<input />' },
    TextareaField: { name: 'TextareaField', props: ['modelValue', 'name', 'title', 'errors', 'rows'], template: '<textarea />' },
    EmailPreviewFrame: { name: 'EmailPreviewFrame', props: ['html'], template: '<div class="preview" />' },
}));

import LegalPageEditor from '../resources/js/components/legal/LegalPageEditor.vue';

const page = {
    slug: 'terms',
    title: { en: 'Terms', uk: 'Умови' },
    body_html: { en: '<p>Hi</p>', uk: '<p>Привіт</p>' },
    version: 3,
    is_published: true,
};

describe('LegalPageEditor', () => {
    it('renders a tab per locale', () => {
        const wrapper = mount(LegalPageEditor, { props: { page, locales: ['en', 'uk'] } });
        const tabs = wrapper.findAll('button').filter((b) => ['EN', 'UK'].includes(b.text()));

        expect(tabs).toHaveLength(2);
    });

    it('shows the current version and a publish toggle', () => {
        const wrapper = mount(LegalPageEditor, { props: { page, locales: ['en', 'uk'] } });

        expect(wrapper.text()).toContain('3');
        // Two checkboxes: publish + require re-acceptance (bump version).
        expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(2);
    });
});
