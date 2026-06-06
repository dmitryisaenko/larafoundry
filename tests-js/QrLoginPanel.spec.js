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

    it('shows an error when generate fails', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 500 }));

        const wrapper = mount(QrLoginPanel, { props: { active: true, pollIntervalMs: 2000 } });
        await flushPromises();

        expect(wrapper.text()).toContain('Could not generate');
    });
});
