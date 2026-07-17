<script setup>
/**
 * Operator security section — the Security tab of the operator profile hub.
 *
 * Extracted from the old standalone Admin/Security/Index page so it can live
 * inside the hub tab (no AdminLayout wrapper, no bordered cards — the hub tab
 * supplies the surface). Behaviour is unchanged: it drives the same
 * `admin.security.*` endpoints, which carry the OTP step-up gating.
 *
 * SECURITY: the QR and recovery codes are NOT fetched from re-readable endpoints.
 * They arrive as props from the server ONLY during enrolment (`two_factor_setup`)
 * or, for review, to a stepped-up session (`recovery_codes`) — so a non-stepped-up
 * session never sees the live secret. The destructive actions (disable / regenerate)
 * live behind the OTP step-up gate; the UI hides them (`can_manage_two_factor`) for
 * a session that has not stepped up, and routes them through the themed confirm()
 * dialog. Enrolment (enable / confirm) and password change stay reachable so an
 * un-enrolled operator can bootstrap their 2FA (the chicken-and-egg).
 */
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { InputField, confirm, useT } from '@dmitryisaenko/larafoundry';
import PinManager from '../../../Profile/PinManager.vue';

defineProps({
    two_factor_enabled: { type: Boolean, default: false },
    // { svg, recovery_codes } during enrolment (secret set, not confirmed), else null.
    two_factor_setup: { type: Object, default: null },
    // Current recovery codes for review — present only for a stepped-up session.
    recovery_codes: { type: Array, default: null },
    can_manage_two_factor: { type: Boolean, default: false },
    has_pin: { type: Boolean, default: false },
    pin_length: { type: Number, default: 4 },
    has_password: { type: Boolean, default: true },
});

const t = useT();

const confirming = ref(false);
const confirmForm = useForm({ code: '' });

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function enableTwoFactor() {
    router.post('/admin/security/two-factor/enable', {}, { preserveScroll: true });
}

function confirmTwoFactor() {
    confirming.value = true;
    confirmForm.post('/admin/security/two-factor/confirm', {
        preserveScroll: true,
        errorBag: 'confirmTwoFactorAuthentication',
        onSuccess: () => confirmForm.reset(),
        onFinish: () => (confirming.value = false),
    });
}

async function regenerateRecoveryCodes() {
    const ok = await confirm({
        variant: 'warning',
        title: t('Regenerate recovery codes'),
        message: t('Your existing recovery codes will stop working.'),
        confirmLabel: t('Regenerate'),
    });
    if (!ok) {
        return;
    }
    router.post('/admin/security/two-factor/recovery-codes', {}, { preserveScroll: true });
}

async function disableTwoFactor() {
    const ok = await confirm({
        variant: 'danger',
        title: t('Disable two-factor authentication'),
        message: t('The operator console will require you to set it up again before you can enter.'),
        confirmLabel: t('Disable'),
    });
    if (!ok) {
        return;
    }
    router.delete('/admin/security/two-factor', { preserveScroll: true });
}

function updatePassword() {
    passwordForm.put('/admin/security/password', {
        preserveScroll: true,
        errorBag: 'updatePassword',
        onSuccess: () => passwordForm.reset(),
        onError: () => passwordForm.reset('current_password', 'password', 'password_confirmation'),
    });
}
</script>

<template>
    <div class="flex flex-col gap-8">
        <!-- Two-factor authentication -->
        <section>
            <header class="mb-4">
                <h2 class="text-base font-semibold text-ink">{{ $t('Two-factor authentication') }}</h2>
                <p class="text-sm text-ink-soft">
                    {{ $t('Add an extra layer of security to the operator console using an authenticator app.') }}
                </p>
            </header>

            <!-- Disabled and not mid-enrolment: offer to enable. -->
            <div v-if="!two_factor_enabled && !two_factor_setup">
                <p class="mb-4 text-sm text-ink-soft">
                    {{ $t('Two-factor authentication is currently disabled.') }}
                </p>
                <button
                    type="button"
                    class="rounded-sm bg-brand-500 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:opacity-50"
                    @click="enableTwoFactor"
                >
                    {{ $t('Enable') }}
                </button>
            </div>

            <!-- Enrolment: scan the QR, store recovery codes, confirm. -->
            <div v-if="two_factor_setup" class="flex flex-col gap-6">
                <div>
                    <p class="mb-3 text-sm text-ink-soft">
                        {{ $t('Scan the QR code with your authenticator app, then enter the generated code below.') }}
                    </p>
                    <!-- QR markup comes from Fortify's server-side SVG writer; trusted. -->
                    <div class="inline-block rounded-sm border border-border bg-white p-3" v-html="two_factor_setup.svg"></div>
                </div>

                <div v-if="two_factor_setup.recovery_codes?.length">
                    <h3 class="mb-2 text-sm font-medium text-ink">{{ $t('Recovery codes') }}</h3>
                    <p class="mb-3 text-xs text-ink-soft">
                        {{ $t('Store these codes somewhere safe. Each can be used once if you lose your device.') }}
                    </p>
                    <ul class="grid grid-cols-2 gap-1 rounded-sm border border-border bg-surface-subtle p-3 font-mono text-sm text-ink">
                        <li v-for="code in two_factor_setup.recovery_codes" :key="code">{{ code }}</li>
                    </ul>
                </div>

                <form class="flex flex-col gap-4" @submit.prevent="confirmTwoFactor">
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
                        class="self-start rounded-sm bg-brand-500 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:opacity-50"
                    >
                        {{ $t('Confirm') }}
                    </button>
                </form>
            </div>

            <!-- Enabled: review/regenerate recovery codes, or disable. -->
            <div v-if="two_factor_enabled && !two_factor_setup" class="flex flex-col gap-6">
                <p class="rounded-sm bg-brand-50 px-3 py-2 text-sm text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                    {{ $t('Two-factor authentication is enabled.') }}
                </p>

                <div v-if="recovery_codes?.length">
                    <h3 class="mb-2 text-sm font-medium text-ink">{{ $t('Recovery codes') }}</h3>
                    <ul class="grid grid-cols-2 gap-1 rounded-sm border border-border bg-surface-subtle p-3 font-mono text-sm text-ink">
                        <li v-for="code in recovery_codes" :key="code">{{ code }}</li>
                    </ul>
                </div>

                <div v-if="can_manage_two_factor" class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="rounded-sm border border-border bg-surface px-4 py-2 text-sm font-medium text-ink transition hover:bg-surface-subtle"
                        @click="regenerateRecoveryCodes"
                    >
                        {{ $t('Regenerate recovery codes') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-sm border border-danger px-4 py-2 text-sm font-medium text-danger transition hover:bg-danger-50 dark:hover:bg-danger-500/10"
                        @click="disableTwoFactor"
                    >
                        {{ $t('Disable') }}
                    </button>
                </div>
                <p v-else class="text-xs text-ink-faint">
                    {{ $t('Enter your one-time code for this session to manage two-factor settings.') }}
                </p>
            </div>
        </section>

        <!-- PIN lock (shared section) -->
        <PinManager :has-pin="has_pin" :length="pin_length" />

        <!-- Change password -->
        <section v-if="has_password">
            <header class="mb-4">
                <h2 class="text-base font-semibold text-ink">{{ $t('Change password') }}</h2>
                <p class="text-sm text-ink-soft">{{ $t('Use a long, unique password to keep the operator account secure.') }}</p>
            </header>

            <form class="flex max-w-xl flex-col gap-3" @submit.prevent="updatePassword">
                <InputField
                    v-model="passwordForm.current_password"
                    name="current_password"
                    type="password"
                    :title="$t('Current password')"
                    :errors="passwordForm.errors"
                    autocomplete="current-password"
                />
                <InputField
                    v-model="passwordForm.password"
                    name="password"
                    type="password"
                    :title="$t('New password')"
                    :errors="passwordForm.errors"
                    autocomplete="new-password"
                />
                <InputField
                    v-model="passwordForm.password_confirmation"
                    name="password_confirmation"
                    type="password"
                    :title="$t('Confirm new password')"
                    :errors="passwordForm.errors"
                    autocomplete="new-password"
                />
                <button
                    type="submit"
                    :disabled="passwordForm.processing"
                    class="self-start rounded-sm bg-brand-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-800 disabled:opacity-50"
                >
                    {{ $t('Update password') }}
                </button>
            </form>
        </section>
    </div>
</template>
