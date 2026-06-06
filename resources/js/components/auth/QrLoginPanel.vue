<script setup>
/**
 * QR cross-device login — web side (phase 1.4 part 2).
 *
 * Embedded in the Login screen as a tab. On mount it asks the backend for a QR
 * (POST larafoundry.qr.generate), renders it, and polls (POST
 * larafoundry.qr.poll) every `pollIntervalMs` until the request is approved on
 * another device, then navigates home via Inertia.
 *
 * Extracted from the donor and modernised:
 *  - uses fetch (the core ships no axios) and Inertia's router.visit, not a raw
 *    window.location, so the SPA stays in control after sign-in;
 *  - clears the poll interval on unmount AND when the panel is hidden (the donor
 *    leaked the interval on tab switch);
 *  - polls every 2s by default (config), aligned with the poll rate-limit, not
 *    the donor's tight 1s.
 *
 * Props:
 *  - pollIntervalMs {number} poll cadence (from config via shared prop)
 *  - active {boolean} whether the QR tab is showing — gates polling so a hidden
 *    panel does not hit the backend.
 */
import { onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    pollIntervalMs: { type: Number, default: 2000 },
    active: { type: Boolean, default: true },
});

const qrCode = ref(null);
const error = ref(null);
let interval = null;

/**
 * Read Laravel's XSRF token from the cookie so the guest POSTs pass the web
 * group's CSRF check without pulling in axios (which would read it for us).
 */
function xsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function post(name) {
    const response = await fetch(route(name), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(`Request failed: ${response.status}`);
    }

    return response.json();
}

async function generate() {
    error.value = null;

    try {
        const data = await post('larafoundry.qr.generate');
        qrCode.value = data.qrCode;
    } catch {
        error.value = 'qr_generate_failed';
    }
}

async function poll() {
    try {
        const data = await post('larafoundry.qr.poll');
        if (data.result) {
            stopPolling();
            router.visit('/');
        }
    } catch {
        // Transient poll errors are ignored; the next tick retries.
    }
}

function startPolling() {
    stopPolling();
    interval = setInterval(poll, props.pollIntervalMs);
}

function stopPolling() {
    if (interval) {
        clearInterval(interval);
        interval = null;
    }
}

// Drive the lifecycle off `active`: generate + poll only while the QR tab is
// shown; stop and drop the code when it is hidden. Runs immediately so opening
// the panel on first render kicks it off.
watch(
    () => props.active,
    (isActive) => {
        if (isActive) {
            generate();
            startPolling();
        } else {
            stopPolling();
            qrCode.value = null;
        }
    },
    { immediate: true },
);

onBeforeUnmount(stopPolling);
</script>

<template>
    <div class="flex flex-col items-center gap-4">
        <p class="text-sm text-ink-soft">
            {{ $t('Scan this code with your phone to sign in.') }}
        </p>

        <div class="flex h-52 w-52 items-center justify-center rounded-md border border-border bg-surface p-2">
            <img
                v-if="qrCode"
                :src="'data:image/svg+xml;base64,' + qrCode"
                :alt="$t('Sign-in QR code')"
                class="h-full w-full"
            />
            <span v-else class="text-sm text-ink-soft">{{ $t('Generating QR code…') }}</span>
        </div>

        <p v-if="error" class="text-sm text-danger">
            {{ $t('Could not generate a QR code. Please try again.') }}
        </p>
    </div>
</template>
