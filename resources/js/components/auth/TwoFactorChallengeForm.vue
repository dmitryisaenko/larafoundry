<script setup>
/**
 * Shared two-factor challenge form.
 *
 * Renders a TOTP `code` / `recovery_code` toggle and POSTs the active field to
 * `action`. Used by both the login-time challenge (Fortify's
 * `/two-factor-challenge`) and the operator-console OTP step-up (`/admin/otp`):
 * only the endpoint, title and prompt differ, so the form lives here once.
 */
import { ref, nextTick } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AuthCard from '../AuthCard.vue';
import InputField from '../ui/InputField.vue';

const props = defineProps({
    /** Endpoint the active credential is POSTed to. */
    action: { type: String, required: true },
    /** Card title. */
    title: { type: String, required: true },
    /** Prompt shown above the TOTP field (recovery mode has its own copy). */
    prompt: { type: String, required: true },
});

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
    // of the payload so the backend receives exactly one field.
    form
        .transform((data) =>
            useRecovery.value
                ? { recovery_code: data.recovery_code }
                : { code: data.code },
        )
        .post(props.action, {
            onFinish: () => form.reset('code', 'recovery_code'),
        });
}
</script>

<template>
    <AuthCard :title="title">
        <p class="mb-6 text-sm text-ink-soft">
            <template v-if="!useRecovery">{{ prompt }}</template>
            <template v-else>{{ $t('Enter one of your emergency recovery codes.') }}</template>
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
</template>
