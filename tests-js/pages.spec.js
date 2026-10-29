import { describe, it, expect } from 'vitest';

import { corePages, pageLoader, resolvePage } from '../resources/js/pages.js';

describe('pages (host first, core as fallback)', () => {
    it('collects the core pages relative to the core itself', () => {
        expect(corePages['./Pages/Tickets/Index.vue']).toBeTypeOf('function');
        expect(corePages['./Pages/Profile/ProfileHub.vue']).toBeTypeOf('function');
    });

    it("prefers the host's page over the core's when both exist", () => {
        const hostLoader = () => Promise.resolve({ default: 'host' });

        expect(pageLoader('Tickets/Index', { './Pages/Tickets/Index.vue': hostLoader })).toBe(hostLoader);
    });

    it("falls back to the core's page when the host has none", () => {
        expect(pageLoader('Tickets/Index', {})).toBe(corePages['./Pages/Tickets/Index.vue']);
    });

    it('serves a host-only page', async () => {
        const page = await resolvePage('Cabinet/Home', {
            './Pages/Cabinet/Home.vue': () => Promise.resolve({ default: 'home' }),
        });

        expect(page.default).toBe('home');
    });

    it('loads the core page itself on fallback', async () => {
        const page = await resolvePage('Tickets/Index', {});

        expect(page.default).toBeTypeOf('object');
    });

    it('throws on a page found in neither place instead of rendering blank', () => {
        expect(() => resolvePage('Nowhere/AtAll', {})).toThrow('Page not found: Nowhere/AtAll');
    });
});
