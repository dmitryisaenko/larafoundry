<script setup>
/**
 * Forgot-password page.
 *
 * POSTs the account email to Laravel Fortify's `/forgot-password` endpoint,
 * which dispatches the password-reset link. Fortify returns a `status` flash
 * on success which we surface as a confirmation message.
 *
 * Props:
 *  - status {string|null} session status flash ("we have emailed your link")
 */
import { ref } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { InputField, AuthScreen } from '@dmitryisaenko/larafoundry';

defineProps({
    status: { type: String, default: null },
});

// Modal-mode visibility (inert in page mode). The modal stays OPEN after a
// successful submit so the "reset link emailed" status shows inside it.
const open = ref(true);

const form = useForm({
    email: '',
});

function submit() {
    form.post('/forgot-password');
}
</script>

<template>
    <AuthScreen
        :title="$t('Forgot password')"
        :subtitle="$t('Enter your email and we will send you a reset link.')"
        :status="status"
        :open="open"
        @close="open = false"
    >
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <InputField
                    v-model="form.email"
                    :title="$t('Email')"
                    name="email"
                    type="email"
                    placeholder="you@example.com"
                    :required="true"
                    :errors="form.errors"
                    autocomplete="email"
                />

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600 disabled:opacity-50"
                >
                    {{ $t('Email password reset link') }}
                </button>
            </form>

        <template #footer>
            <Link href="/login" class="text-brand-600 hover:text-brand-700">{{ $t('Back to sign in') }}</Link>
        </template>
    </AuthScreen>
</template>
