<script setup>
/**
 * Login page.
 *
 * POSTs credentials to Laravel Fortify's `/login` endpoint. When the user has
 * two-factor authentication enabled, Fortify responds (in Inertia mode) by
 * redirecting to the two-factor-challenge route automatically — no extra
 * client handling is needed here, so we simply submit the form.
 *
 * Props:
 *  - canResetPassword {boolean} whether to surface the "Forgot password" link
 *  - status {string|null} optional session status flash (e.g. after reset)
 */
import { useForm, Link } from '@inertiajs/vue3';
import InputField from '../../components/ui/InputField.vue';
import AuthCard from '../../components/AuthCard.vue';
import AppBaseLayout from '../../layouts/AppBaseLayout.vue';

defineProps({
    canResetPassword: { type: Boolean, default: false },
    status: { type: String, default: null },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

// OAuth providers — the buttons render unconditionally and are guarded
// server-side; the `/auth/oauth/{provider}` route decides availability.
const oauthProviders = ['google', 'github'];

function submit() {
    // On success with 2FA enabled, Fortify redirects to the two-factor
    // challenge route automatically; otherwise it redirects to the dashboard.
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <AppBaseLayout>
        <AuthCard
            :title="$t('Sign in')"
            :subtitle="$t('Welcome back. Please enter your details.')"
            :status="status"
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

                <InputField
                    v-model="form.password"
                    :title="$t('Password')"
                    name="password"
                    type="password"
                    :required="true"
                    :errors="form.errors"
                    autocomplete="current-password"
                />

                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-2 text-sm text-ink-soft">
                        <input
                            v-model="form.remember"
                            type="checkbox"
                            name="remember"
                            class="rounded-sm border-border text-brand-500 focus:ring-brand-200"
                        />
                        {{ $t('Remember me') }}
                    </label>

                    <Link
                        v-if="canResetPassword"
                        href="/forgot-password"
                        class="text-sm text-brand-600 hover:text-brand-700"
                    >
                        {{ $t('Forgot password?') }}
                    </Link>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600 disabled:opacity-50"
                >
                    {{ $t('Sign in') }}
                </button>
            </form>

            <div class="my-6 flex items-center gap-3 text-xs text-ink-soft">
                <span class="h-px flex-1 bg-border"></span>
                {{ $t('Or continue with') }}
                <span class="h-px flex-1 bg-border"></span>
            </div>

            <div class="flex flex-col gap-2">
                <a
                    v-for="provider in oauthProviders"
                    :key="provider"
                    :href="'/auth/oauth/' + provider"
                    class="flex items-center justify-center rounded-sm border border-border bg-surface px-4 py-2 text-sm font-medium text-ink capitalize transition hover:bg-surface-subtle"
                >
                    {{ $t('Continue with') }} {{ provider }}
                </a>
            </div>

            <template #footer>
                {{ $t("Don't have an account?") }}
                <Link href="/register" class="text-brand-600 hover:text-brand-700">{{ $t('Register') }}</Link>
            </template>
        </AuthCard>
    </AppBaseLayout>
</template>
