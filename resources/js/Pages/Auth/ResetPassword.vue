<script setup>
/**
 * Reset-password page.
 *
 * Reached from the emailed reset link. POSTs the token + new password to
 * Laravel Fortify's `/reset-password` endpoint. The `token` and `email`
 * arrive as page props (seeded from the signed link) and travel back in the
 * form payload.
 *
 * Props:
 *  - email {string} pre-filled account email from the reset link
 *  - token {string} the signed password-reset token
 */
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { InputField, AuthScreen } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    email: { type: String, default: '' },
    token: { type: String, required: true },
});

// Modal-mode visibility (inert in page mode); closed on a successful reset.
const open = ref(true);

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/reset-password', {
        onSuccess: () => {
            open.value = false;
        },
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <AuthScreen
        :title="$t('Reset password')"
        :subtitle="$t('Choose a new password for your account.')"
        :open="open"
        @close="open = false"
    >
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <InputField
                    v-model="form.email"
                    :title="$t('Email')"
                    name="email"
                    type="email"
                    :required="true"
                    :errors="form.errors"
                    autocomplete="email"
                />

                <InputField
                    v-model="form.password"
                    :title="$t('New password')"
                    name="password"
                    type="password"
                    :required="true"
                    :errors="form.errors"
                    autocomplete="new-password"
                />

                <InputField
                    v-model="form.password_confirmation"
                    :title="$t('Confirm password')"
                    name="password_confirmation"
                    type="password"
                    :required="true"
                    :errors="form.errors"
                    autocomplete="new-password"
                />

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600 disabled:opacity-50"
                >
                    {{ $t('Reset password') }}
                </button>
            </form>
    </AuthScreen>
</template>
