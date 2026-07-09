import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, enableAutoUnmount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

// Minimal useForm stub: a reactive-enough object exposing the fields the form
// binds plus the chainable methods it calls.
vi.mock('@inertiajs/vue3', () => ({
    useForm: (initial) => ({
        ...initial,
        errors: {},
        processing: false,
        put: vi.fn(),
        post: vi.fn(),
        submit: vi.fn(),
        reset: vi.fn(),
        transform() {
            return this;
        },
    }),
    router: { delete: vi.fn(), post: vi.fn() },
}));

vi.mock('@dmitryisaenko/larafoundry', () => ({
    InputField: { name: 'InputField', props: ['modelValue', 'name', 'title', 'errors', 'readonly'], template: '<input />' },
    TextareaField: { name: 'TextareaField', props: ['modelValue', 'name', 'title', 'errors', 'rows'], template: '<textarea />' },
    SelectField: { name: 'SelectField', props: ['modelValue', 'valueNameArray'], template: '<select />' },
    EmailPreviewFrame: { name: 'EmailPreviewFrame', props: ['html'], template: '<div class="preview" />' },
    confirm: vi.fn(),
    useT: () => (key) => key,
}));

import MarketingEmailTemplateForm from '../resources/js/components/email/MarketingEmailTemplateForm.vue';

function slugifyVia(mode = 'create') {
    const wrapper = mount(MarketingEmailTemplateForm, {
        props: { template: null, locales: ['en', 'uk'], mode },
    });
    return wrapper.vm.slugify;
}

describe('MarketingEmailTemplateForm slugify', () => {
    it('always produces a code matching the server rule ^[a-z][a-z0-9_]*$ or empty', () => {
        const slugify = slugifyVia();
        const rule = /^[a-z][a-z0-9_]*$/;

        for (const name of ['Launch 2024', '2024 Launch', '  Summer!! Promo  ', 'A', 'Ласкаво Promo']) {
            const code = slugify(name);
            expect(code === '' || rule.test(code)).toBe(true);
        }
    });

    it('strips a leading digit/symbol run instead of prepending an underscore', () => {
        const slugify = slugifyVia();

        expect(slugify('2024 Launch')).toBe('launch');
        expect(slugify('Launch 2024')).toBe('launch_2024');
        expect(slugify('Summer Promo')).toBe('summer_promo');
    });

    it('returns empty when a name has no leading letter, so the operator fills the code', () => {
        const slugify = slugifyVia();

        expect(slugify('2024')).toBe('');
        expect(slugify('!!!')).toBe('');
    });
});
