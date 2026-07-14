import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

/**
 * The <Seo> component reads the `seo` shared Inertia prop and re-emits the head
 * tags through Inertia's <Head> on SPA navigation (phase 5.2). We mock
 * `@inertiajs/vue3` so each test controls the page props, and stub <Head> as a
 * passthrough that renders its slot so the emitted tags are assertable.
 */
const { pageProps } = vi.hoisted(() => ({ pageProps: { current: {} } }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps.current }),
    Head: { template: '<div class="head-stub"><slot /></div>' },
}));

import Seo from '../../resources/js/components/Seo.vue';

function fullSeo() {
    return {
        title: 'Hello world',
        description: 'A friendly place',
        canonical: 'https://a.test/page',
        robots: 'index,follow',
        og: { type: 'website', image: 'https://cdn.test/og.png', site_name: 'Kohana' },
        twitter: { card: 'summary_large_image', site: '@kohana' },
        hreflangs: { en: 'https://a.test/page', uk: 'https://a.test/page', 'x-default': 'https://a.test/page' },
    };
}

describe('Seo', () => {
    beforeEach(() => {
        pageProps.current = {};
    });

    it('renders title and meta tags from the seo prop', () => {
        pageProps.current = { seo: fullSeo() };

        const html = mount(Seo).html();

        expect(html).toContain('Hello world');
        expect(html).toContain('name="description"');
        expect(html).toContain('rel="canonical"');
        expect(html).toContain('property="og:image"');
        expect(html).toContain('name="twitter:card"');
        expect(html).toContain('hreflang="en"');
        expect(html).toContain('hreflang="x-default"');
    });

    it('drops a non-http canonical / image', () => {
        pageProps.current = {
            seo: { ...fullSeo(), canonical: 'javascript:alert(1)', og: { type: 'website', image: 'data:image/png,x' } },
        };

        const html = mount(Seo).html();

        expect(html).not.toContain('javascript:');
        expect(html).not.toContain('rel="canonical"');
        expect(html).not.toContain('property="og:image"');
    });

    it('renders nothing when the seo prop is missing', () => {
        pageProps.current = {};

        const wrapper = mount(Seo);

        expect(wrapper.html()).not.toContain('name="description"');
        expect(wrapper.html()).not.toContain('rel="canonical"');
    });

    it('mounts without error when given no props at all', () => {
        pageProps.current = { seo: undefined };

        expect(() => mount(Seo)).not.toThrow();
    });
});
