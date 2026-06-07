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
        post: vi.fn(),
        reset: vi.fn(),
        transform() {
            return this;
        },
    }),
}));

vi.mock('@dmitryisaenko/larafoundry', () => ({
    InputField: { name: 'InputField', props: ['modelValue', 'name', 'title', 'errors'], template: '<input />' },
    TextareaField: { name: 'TextareaField', props: ['modelValue', 'name', 'title', 'errors', 'rows'], template: '<textarea />' },
    SelectField: { name: 'SelectField', props: ['modelValue', 'valueNameArray'], template: '<select />' },
    EmailPreviewFrame: { name: 'EmailPreviewFrame', props: ['html'], template: '<div class="preview" />' },
}));

import EmailTemplateEditor from '../resources/js/components/email/EmailTemplateEditor.vue';

const template = {
    code: 'welcome',
    variables: ['name', 'app_name'],
    is_active: true,
    subject: { en: 'Hi {{name}}', uk: 'Привіт' },
    body_html: { en: '<p>Hi</p>', uk: '<p>Привіт</p>' },
    body_text: { en: 'Hi', uk: 'Привіт' },
};

describe('EmailTemplateEditor', () => {
    it('renders a tab per locale', () => {
        const wrapper = mount(EmailTemplateEditor, { props: { template, locales: ['en', 'uk'] } });
        const tabs = wrapper.findAll('button').filter((b) => ['EN', 'UK'].includes(b.text()));

        expect(tabs).toHaveLength(2);
    });

    it('lists the template variables as {{token}} chips', () => {
        const wrapper = mount(EmailTemplateEditor, { props: { template, locales: ['en', 'uk'] } });

        expect(wrapper.text()).toContain('{{name}}');
        expect(wrapper.text()).toContain('{{app_name}}');
    });
});
