<script setup>
/**
 * Two-factor authentication enrolment & management.
 *
 * Authenticated settings screen driving Laravel Fortify's headless 2FA flow:
 *
 *   1. Enable        POST   /user/two-factor-authentication
 *   2. Show QR       GET    /user/two-factor-qr-code            → { svg }
 *      Recovery codes GET   /user/two-factor-recovery-codes     → string[]
 *   3. Confirm       POST   /user/confirmed-two-factor-authentication  (code)
 *   4. Regenerate    POST   /user/two-factor-recovery-codes
 *   5. Disable       DELETE /user/two-factor-authentication
 *
 * The two GET reads use the browser `fetch()` API (Fortify exposes them as
 * JSON, not as Inertia responses); the mutations go through Inertia `router`
 * so CSRF, redirects and flash handling stay consistent with the rest of the
 * app.
 *
 * Props:
 *  - twoFactorEnabled {boolean} whether 2FA is already fully confirmed
 */
import { ref, onMounted } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { InputField, AuthCard, AppBaseLayout } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    twoFactorEnabled: { type: Boolean, default: false },
});

// UI state.
const enabling = ref(false); // request in flight to enable 2FA
const confirming = ref(false); // request in flight to confirm the TOTP code
const enabled = ref(props.twoFactorEnabled); // 2FA fully confirmed & active
const showingQr = ref(false); // enabled-but-not-yet-confirmed enrolment shown
const qrSvg = ref(''); // raw <svg> markup for the setup QR code
const recoveryCodes = ref([]); // emergency recovery codes (string[])

const confirmForm = useForm({
    code: '',
});

/** Read the setup QR code SVG from Fortify. */
async function fetchQrCode() {
    const response = await fetch('/user/two-factor-qr-code', {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });
    const data = await response.json();
    qrSvg.value = data.svg;
}

/** Read the current recovery codes from Fortify. */
async function fetchRecoveryCodes() {
    const response = await fetch('/user/two-factor-recovery-codes', {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });
    recoveryCodes.value = await response.json();
}

/** Step 1 — enable 2FA, then pull the QR + recovery codes for enrolment. */
function enable() {
    router.post(
        '/user/two-factor-authentication',
        {},
        {
            preserveScroll: true,
            onStart: () => (enabling.value = true),
            onSuccess: async () => {
                showingQr.value = true;
                await Promise.all([fetchQrCode(), fetchRecoveryCodes()]);
            },
            onFinish: () => (enabling.value = false),
        },
    );
}

/** Step 3 — confirm the enrolment with a TOTP code from the authenticator. */
function confirm() {
    confirming.value = true;
    confirmForm.post('/user/confirmed-two-factor-authentication', {
        preserveScroll: true,
        onSuccess: () => {
            enabled.value = true;
            showingQr.value = false;
            confirmForm.reset();
        },
        onFinish: () => (confirming.value = false),
    });
}

/** Generate a fresh set of recovery codes (invalidates the old set). */
function regenerateRecoveryCodes() {
    router.post(
        '/user/two-factor-recovery-codes',
        {},
        {
            preserveScroll: true,
            onSuccess: () => fetchRecoveryCodes(),
        },
    );
}

/** Disable 2FA entirely and reset the local enrolment state. */
function disable() {
    router.delete('/user/two-factor-authentication', {
        preserveScroll: true,
        onSuccess: () => {
            enabled.value = false;
            showingQr.value = false;
            qrSvg.value = '';
            recoveryCodes.value = [];
        },
    });
}

// If 2FA is already active, load the recovery codes so they can be reviewed.
onMounted(() => {
    if (enabled.value) {
        fetchRecoveryCodes();
    }
});
</script>

<template>
    <AppBaseLayout>
        <AuthCard
            :title="$t('Two-factor authentication')"
            :subtitle="$t('Add an extra layer of security to your account using an authenticator app.')"
        >
            <!-- Disabled state: offer to enable. -->
            <div v-if="!enabled && !showingQr">
                <p class="mb-4 text-sm text-ink-soft">
                    {{ $t('Two-factor authentication is currently disabled.') }}
                </p>
                <button
                    type="button"
                    :disabled="enabling"
                    class="rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600 disabled:opacity-50"
                    @click="enable"
                >
                    {{ $t('Enable') }}
                </button>
            </div>

            <!-- Enrolment state: scan the QR, store recovery codes, confirm. -->
            <div v-if="showingQr" class="flex flex-col gap-6">
                <div>
                    <p class="mb-3 text-sm text-ink-soft">
                        {{ $t('Scan the QR code with your authenticator app, then enter the generated code below.') }}
                    </p>
                    <!-- QR markup comes from Fortify; rendered as trusted SVG. -->
                    <div class="inline-block rounded-sm border border-border bg-white p-3" v-html="qrSvg"></div>
                </div>

                <div v-if="recoveryCodes.length">
                    <h2 class="mb-2 text-sm font-medium text-ink">{{ $t('Recovery codes') }}</h2>
                    <p class="mb-3 text-xs text-ink-soft">
                        {{ $t('Store these codes somewhere safe. Each can be used once if you lose your device.') }}
                    </p>
                    <ul class="grid grid-cols-2 gap-1 rounded-sm border border-border bg-surface-subtle p-3 font-mono text-sm text-ink">
                        <li v-for="code in recoveryCodes" :key="code">{{ code }}</li>
                    </ul>
                </div>

                <form class="flex flex-col gap-4" @submit.prevent="confirm">
                    <InputField
                        v-model="confirmForm.code"
                        :title="$t('Authentication code')"
                        name="code"
                        type="text"
                        placeholder="123456"
                        :required="true"
                        :errors="confirmForm.errors"
                        autocomplete="one-time-code"
                        inputmode="numeric"
                    />
                    <button
                        type="submit"
                        :disabled="confirming"
                        class="rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600 disabled:opacity-50"
                    >
                        {{ $t('Confirm') }}
                    </button>
                </form>
            </div>

            <!-- Enabled state: review/regenerate recovery codes, or disable. -->
            <div v-if="enabled && !showingQr" class="flex flex-col gap-6">
                <p class="rounded-sm bg-brand-50 px-3 py-2 text-sm text-brand-700">
                    {{ $t('Two-factor authentication is enabled.') }}
                </p>

                <div v-if="recoveryCodes.length">
                    <h2 class="mb-2 text-sm font-medium text-ink">{{ $t('Recovery codes') }}</h2>
                    <ul class="grid grid-cols-2 gap-1 rounded-sm border border-border bg-surface-subtle p-3 font-mono text-sm text-ink">
                        <li v-for="code in recoveryCodes" :key="code">{{ code }}</li>
                    </ul>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="rounded-sm border border-border bg-surface px-4 py-2 text-sm font-medium text-ink transition hover:bg-surface-subtle"
                        @click="regenerateRecoveryCodes"
                    >
                        {{ $t('Regenerate recovery codes') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-sm border border-danger px-4 py-2 text-sm font-medium text-danger transition hover:bg-danger-50"
                        @click="disable"
                    >
                        {{ $t('Disable') }}
                    </button>
                </div>
            </div>
        </AuthCard>
    </AppBaseLayout>
</template>
