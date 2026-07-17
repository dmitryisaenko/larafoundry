import { describe, it, expect, beforeEach, vi } from 'vitest';

// Capture the Inertia router event handlers so we can drive the "live re-apply on
// a successful visit" path without a real Inertia app.
const handlers = {};
vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: (event, cb) => { handlers[event] = cb; },
    },
}));

import { setupTheme, useTheme } from '../resources/js/composables/useTheme.js';

beforeEach(() => {
    document.documentElement.className = '';
    document.documentElement.removeAttribute('data-theme');
});

describe('useTheme applier', () => {
    it('adds the dark class for an explicit dark preference', () => {
        setupTheme({ ui_settings: { theme: 'dark' } });
        expect(document.documentElement.classList.contains('dark')).toBe(true);
        expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
    });

    it('removes the dark class for a light preference', () => {
        document.documentElement.classList.add('dark');
        setupTheme({ ui_settings: { theme: 'light' } });
        expect(document.documentElement.classList.contains('dark')).toBe(false);
        expect(document.documentElement.getAttribute('data-theme')).toBe('light');
    });

    it('falls back to the appearance prop, then to system', () => {
        setupTheme({ appearance: 'light' });
        expect(document.documentElement.getAttribute('data-theme')).toBe('light');

        // No preference at all → 'system'; jsdom ships no matchMedia, so system
        // resolves to light (never throws).
        setupTheme({});
        expect(document.documentElement.getAttribute('data-theme')).toBe('system');
        expect(document.documentElement.classList.contains('dark')).toBe(false);
    });

    it('re-applies live on a successful Inertia visit (theme change without a reload)', () => {
        setupTheme({ ui_settings: { theme: 'light' } });
        expect(document.documentElement.classList.contains('dark')).toBe(false);

        // The Appearance tab PUTs the new preference; Inertia returns fresh
        // ui_settings on the visit — the applier must flip the class off that.
        handlers.success({ detail: { page: { props: { ui_settings: { theme: 'dark' } } } } });
        expect(document.documentElement.classList.contains('dark')).toBe(true);
    });

    it('exposes applyTheme for manual control', () => {
        const { applyTheme } = useTheme();
        applyTheme('dark');
        expect(document.documentElement.classList.contains('dark')).toBe(true);
        applyTheme('light');
        expect(document.documentElement.classList.contains('dark')).toBe(false);
    });
});
