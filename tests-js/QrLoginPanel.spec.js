import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

// Mock Inertia's router so we can assert navigation on a successful poll.
const visit = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    router: { visit: (...args) => visit(...args) },
}));

import QrLoginPanel from '../resources/js/components/auth/QrLoginPanel.vue';

describe('QrLoginPanel', () => {
    beforeEach(() => {
        visit.mockClear();
        // The panel calls the global `route()` helper for endpoint URLs.
        global.route = (name) => `/__/${name}`;
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
        delete global.route;
    });

    function fetchReturning(sequence) {
        let i = 0;

        global.fetch = vi.fn(() => {
            const body = sequence[Math.min(i, sequence.length - 1)];
            i += 1;

            return Promise.resolve({ ok: true, json: () => Promise.resolve(body) });
        });
    }

    it('generates a QR on mount and renders it', async () => {
        fetchReturning([{ qrCode: 'BASE64SVG' }]);

        const wrapper = mount(QrLoginPanel, { props: { active: true, pollIntervalMs: 2000 } });
        await flushPromises();

        const img = wrapper.find('img');
        expect(img.exists()).toBe(true);
        expect(img.attributes('src')).toContain('BASE64SVG');
        // First call is generate.
        expect(global.fetch.mock.calls[0][0]).toContain('larafoundry.qr.generate');
    });

    it('navigates home when a poll reports approval', async () => {
        // generate → then poll returns result:true.
        fetchReturning([{ qrCode: 'X' }, { result: true }]);

        mount(QrLoginPanel, { props: { active: true, pollIntervalMs: 2000 } });
        await flushPromises();

        // Advance to the first poll tick.
        await vi.advanceTimersByTimeAsync(2000);
        await flushPromises();

        expect(visit).toHaveBeenCalledWith('/');
    });

    it('stops polling once it is hidden', async () => {
        fetchReturning([{ qrCode: 'X' }, { result: false }]);

        const wrapper = mount(QrLoginPanel, { props: { active: true, pollIntervalMs: 2000 } });
        await flushPromises();

        await wrapper.setProps({ active: false });
        await flushPromises();

        const callsAfterHide = global.fetch.mock.calls.length;

        await vi.advanceTimersByTimeAsync(6000);
        await flushPromises();

        // No further polls fired after the panel was hidden.
        expect(global.fetch.mock.calls.length).toBe(callsAfterHide);
    });

    it('retries generate once silently and succeeds without showing an error', async () => {
        // First POST fails (as a stale-CSRF 419 would), the silent retry succeeds.
        let i = 0;
        global.fetch = vi.fn(() => {
            i += 1;
            if (i === 1) {
                return Promise.resolve({ ok: false, status: 419 });
            }

            return Promise.resolve({ ok: true, json: () => Promise.resolve({ qrCode: 'RETRIED' }) });
        });

        const wrapper = mount(QrLoginPanel, { props: { active: true, pollIntervalMs: 2000 } });
        await flushPromises();

        // During the retry backoff we stay in the "generating" state, not an error.
        expect(wrapper.text()).not.toContain('Could not load the QR code.');

        // Let the retry's short delay elapse, then it resolves with a QR.
        await vi.advanceTimersByTimeAsync(600);
        await flushPromises();

        const img = wrapper.find('img');
        expect(img.exists()).toBe(true);
        expect(img.attributes('src')).toContain('RETRIED');
        expect(wrapper.text()).not.toContain('Could not load the QR code.');
    });

    it('shows only the error state after the retry also fails', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 500 }));

        const wrapper = mount(QrLoginPanel, { props: { active: true, pollIntervalMs: 2000 } });
        await flushPromises();
        await vi.advanceTimersByTimeAsync(600);
        await flushPromises();

        const text = wrapper.text();
        // The three box states are mutually exclusive: only the error is shown.
        expect(text).toContain('Could not load the QR code.');
        expect(text).toContain('Try again');
        expect(text).not.toContain('Scan this code with your phone to sign in.');
        expect(text).not.toContain('Generating QR code');
    });

    it('regenerates when Try again is clicked', async () => {
        // Initial POST + its auto-retry both fail, then a manual retry succeeds.
        let i = 0;
        global.fetch = vi.fn(() => {
            i += 1;
            if (i <= 2) {
                return Promise.resolve({ ok: false, status: 500 });
            }

            return Promise.resolve({ ok: true, json: () => Promise.resolve({ qrCode: 'AFTERCLICK' }) });
        });

        const wrapper = mount(QrLoginPanel, { props: { active: true, pollIntervalMs: 2000 } });
        await flushPromises();
        await vi.advanceTimersByTimeAsync(600);
        await flushPromises();
        expect(wrapper.text()).toContain('Could not load the QR code.');

        await wrapper.find('button').trigger('click');
        await flushPromises();

        const img = wrapper.find('img');
        expect(img.exists()).toBe(true);
        expect(img.attributes('src')).toContain('AFTERCLICK');
    });
});
