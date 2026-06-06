<script setup>
/**
 * QR cross-device login — scanning device side (phase 1.4 part 2).
 *
 * The already-authenticated device scans the QR shown on the web side and calls
 * its verify URL to approve the sign-in. Extracted from the donor and hardened:
 *
 *  - SSRF / open-redirect fix (donor hole #3): the donor did a blind
 *    `axios.get(decodedText)` on whatever the QR decoded to. Here the decoded URL
 *    is validated FIRST — it must be same-origin AND match the expected
 *    `/larafoundry/qr/verify/{id}/{token}` path — before any request is made. A
 *    foreign or malformed code is rejected, never fetched.
 *  - html5-qrcode is lazy-imported so it is not in the main bundle for hosts that
 *    never mount the scanner.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';

const error = ref(null);
let scanner = null;
let restartTimeout = null;

/**
 * Accept the decoded value only if it is a same-origin URL whose path is the
 * QR verify endpoint. Returns the safe URL string or null.
 */
function safeVerifyUrl(decoded) {
    let url;

    try {
        url = new URL(decoded, window.location.origin);
    } catch {
        return null;
    }

    // Only real http(s) URLs — reject blob:/data:/javascript: etc. before the
    // origin check (a blob: URL inherits the page origin but is not a verify URL).
    if (url.protocol !== 'https:' && url.protocol !== 'http:') {
        return null;
    }

    if (url.origin !== window.location.origin) {
        return null;
    }

    // The verify path, optionally behind a single prefix segment (the host may
    // mount the API group under `/api`). Same-origin is already enforced above;
    // the server re-validates the hashed token regardless.
    return /^(?:\/[^/]+)?\/larafoundry\/qr\/verify\/\d+\/[^/]+$/.test(url.pathname)
        ? url.toString()
        : null;
}

async function approve(url) {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        throw new Error(body.error || 'verify_failed');
    }
}

async function onScan(decodedText) {
    await stopScanner();

    const url = safeVerifyUrl(decodedText);

    if (url === null) {
        showError('qr_foreign_code');

        return;
    }

    try {
        await approve(url);
        error.value = null;
        emit('approved');
    } catch {
        showError('qr_verify_failed');
    }
}

function showError(key) {
    error.value = key;
    restartTimeout = setTimeout(() => {
        error.value = null;
        startScanner();
    }, 2000);
}

async function startScanner() {
    const { Html5Qrcode } = await import('html5-qrcode');

    scanner = new Html5Qrcode('larafoundry-qr-reader');

    const cameras = await Html5Qrcode.getCameras();

    if (!cameras.length) {
        error.value = 'qr_no_camera';

        return;
    }

    scanner.start({ facingMode: 'environment' }, { fps: 10 }, onScan, () => {});
}

async function stopScanner() {
    if (scanner) {
        try {
            await scanner.stop();
        } catch {
            // Already stopped — ignore.
        }
    }
}

const emit = defineEmits(['approved']);

onMounted(startScanner);

onBeforeUnmount(() => {
    stopScanner();
    if (restartTimeout) {
        clearTimeout(restartTimeout);
    }
});
</script>

<template>
    <div class="relative flex flex-col items-center gap-3">
        <div id="larafoundry-qr-reader" class="w-64 rounded-md border border-border p-3"></div>

        <p v-if="error === 'qr_foreign_code'" class="text-sm text-danger">
            {{ $t('That code is not a sign-in code for this site.') }}
        </p>
        <p v-else-if="error === 'qr_verify_failed'" class="text-sm text-danger">
            {{ $t('Could not approve the sign-in. Please try again.') }}
        </p>
        <p v-else-if="error === 'qr_no_camera'" class="text-sm text-danger">
            {{ $t('No camera found.') }}
        </p>
    </div>
</template>
