import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    useForm: () => ({ post, processing: false }),
}));

vi.mock('@dmitryisaenko/larafoundry', () => ({
    AppBaseLayout: { template: '<div><slot /></div>' },
    LogoutForm: { template: '<button type="submit"><slot /></button>' },
}));

import Accept from '../../resources/js/Pages/Legal/Accept.vue';

const terms_url = 'https://example.test/legal/terms';

describe('Legal/Accept page', () => {
    it('opens the terms with a plain link in a new tab, which a host template can answer', () => {
        const link = mount(Accept, { props: { terms_url } }).find('a');

        expect(link.attributes('href')).toBe(terms_url);
        expect(link.attributes('target')).toBe('_blank');
        expect(link.attributes('rel')).toBe('noopener');
    });

    it('does not tell a first-time reader that the terms have changed', () => {
        const text = mount(Accept, { props: { terms_url, first_time: true } }).text();

        expect(text).toContain('Please accept our terms');
        expect(text).toContain('Please review and accept the Terms of Service to continue.');
        expect(text).not.toContain('Our terms have changed');
    });

    it('tells someone who accepted an older version that they changed', () => {
        const text = mount(Accept, { props: { terms_url, first_time: false } }).text();

        expect(text).toContain('Our terms have changed');
        expect(text).toContain('Please review and accept the updated Terms of Service to continue.');
    });

    it('posts the acceptance', async () => {
        const wrapper = mount(Accept, { props: { terms_url } });

        await wrapper.find('button[type="button"]').trigger('click');

        expect(post).toHaveBeenCalledWith('/terms/accept', { preserveScroll: true });
    });
});
