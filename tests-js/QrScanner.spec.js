import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

// Mock the lazy-imported html5-qrcode so the scanner mounts without a camera.
// We capture the onScan callback so tests can feed it decoded values directly.
let capturedOnScan = null;

vi.mock('html5-qrcode', () => {
    class Html5Qrcode {
        static getCameras() {
            return Promise.resolve([{ id: 'cam-1' }]);
        }

        start(_camera, _config, onScan) {
            capturedOnScan = onScan;

            return Promise.resolve();
        }

        stop() {
            return Promise.resolve();
        }
    }

    return { Html5Qrcode };
});

import QrScanner from '../resources/js/components/auth/QrScanner.vue';

describe('QrScanner SSRF guard', () => {
    beforeEach(() => {
        capturedOnScan = null;
        // jsdom default origin is http://localhost:3000.
        global.fetch = vi.fn(() =>
            Promise.resolve({ ok: true, json: () => Promise.resolve({}) }),
        );
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    async function mountScanner() {
        const wrapper = mount(QrScanner, { attachTo: document.body });
        await flushPromises();

        return wrapper;
    }

    it('fetches a same-origin verify URL', async () => {
        const wrapper = await mountScanner();

        const url = `${window.location.origin}/larafoundry/qr/verify/1/abc-token`;
        await capturedOnScan(url);
        await flushPromises();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(global.fetch.mock.calls[0][0]).toContain('/larafoundry/qr/verify/1/abc-token');
        expect(wrapper.emitted('approved')).toHaveLength(1);
    });

    it('rejects a foreign-origin URL without fetching', async () => {
        await mountScanner();

        await capturedOnScan('https://evil.example.com/larafoundry/qr/verify/1/abc');
        await flushPromises();

        expect(global.fetch).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('not a sign-in code');
    });

    it('rejects a same-origin URL with a non-verify path without fetching', async () => {
        await mountScanner();

        await capturedOnScan(`${window.location.origin}/admin/secret`);
        await flushPromises();

        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('rejects a non-URL decoded value without fetching', async () => {
        await mountScanner();

        await capturedOnScan('javascript:alert(1)');
        await flushPromises();

        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('rejects a blob: URL even though it inherits the page origin', async () => {
        await mountScanner();

        await capturedOnScan(`blob:${window.location.origin}/larafoundry/qr/verify/1/abc`);
        await flushPromises();

        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('accepts the verify URL behind an /api prefix', async () => {
        const wrapper = await mountScanner();

        await capturedOnScan(`${window.location.origin}/api/larafoundry/qr/verify/2/tok-2`);
        await flushPromises();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(wrapper.emitted('approved')).toHaveLength(1);
    });
});
