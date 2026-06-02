<script setup>
/**
 * Two-factor authentication challenge.
 *
 * Shown after a valid password login when 2FA is enabled. POSTs either the
 * TOTP `code` OR the `recovery_code` to Laravel Fortify's
 * `/two-factor-challenge` endpoint. A toggle switches between the two modes,
 * and only the active field is sent so Fortify validates the right credential.
 */
import { ref, nextTick } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { InputField, AuthCard, AppBaseLayout } from '@dmitryisaenko/larafoundry';

const useRecovery = ref(false);

const form = useForm({
    code: '',
    recovery_code: '',
});

async function toggleMode() {
    useRecovery.value = !useRecovery.value;
    // Clear both fields so we never submit a stale value from the other mode.
    form.reset('code', 'recovery_code');
    form.clearErrors();
    await nextTick();
}

function submit() {
    // Submit only the active credential; the inactive one is transformed out
    // of the payload so Fortify receives exactly one field.
    form
        .transform((data) =>
            useRecovery.value
                ? { recovery_code: data.recovery_code }
                : { code: data.code },
        )
        .post('/two-factor-challenge', {
            onFinish: () => form.reset('code', 'recovery_code'),
        });
}
</script>

<template>
    <AppBaseLayout>
        <AuthCard :title="$t('Two-factor authentication')">
            <p class="mb-6 text-sm text-ink-soft">
                <template v-if="!useRecovery">
                    {{ $t('Enter the authentication code from your authenticator app.') }}
                </template>
                <template v-else>
                    {{ $t('Enter one of your emergency recovery codes.') }}
                </template>
            </p>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <InputField
                    v-if="!useRecovery"
                    v-model="form.code"
                    :title="$t('Authentication code')"
                    name="code"
                    type="text"
                    placeholder="123456"
                    :required="true"
                    :errors="form.errors"
                    autocomplete="one-time-code"
                    inputmode="numeric"
                />

                <InputField
                    v-else
                    v-model="form.recovery_code"
                    :title="$t('Recovery code')"
                    name="recovery_code"
                    type="text"
                    :required="true"
                    :errors="form.errors"
                    autocomplete="one-time-code"
                />

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600 disabled:opacity-50"
                >
                    {{ $t('Verify') }}
                </button>
            </form>

            <template #footer>
                <button type="button" class="text-brand-600 hover:text-brand-700" @click="toggleMode">
                    <template v-if="!useRecovery">{{ $t('Use a recovery code') }}</template>
                    <template v-else>{{ $t('Use an authentication code') }}</template>
                </button>
            </template>
        </AuthCard>
    </AppBaseLayout>
</template>
