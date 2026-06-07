<script setup>
/**
 * Registration page.
 *
 * POSTs the new account fields to Laravel Fortify's `/register` endpoint.
 * On success Fortify logs the user in and redirects.
 */
import { ref, computed } from 'vue';
import { useForm, usePage, Link } from '@inertiajs/vue3';
import { InputField, AuthScreen } from '@dmitryisaenko/larafoundry';

// Modal-mode visibility (inert in page mode); closed on a successful register.
const open = ref(true);

// Show the Terms checkbox only when a published Terms page must be accepted
// (phase 5.3 part B). The backend enforces the same condition, so the two never
// disagree: no published Terms = no checkbox and no requirement.
const page = usePage();
const termsRequired = computed(() => page.props.consent?.terms_required === true);

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    terms: false,
});

function submit() {
    form.post('/register', {
        onSuccess: () => {
            open.value = false;
        },
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <AuthScreen
        :title="$t('Create an account')"
        :subtitle="$t('Get started in less than a minute.')"
        :open="open"
        @close="open = false"
    >
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <InputField
                    v-model="form.name"
                    :title="$t('Name')"
                    name="name"
                    type="text"
                    :required="true"
                    :errors="form.errors"
                    autocomplete="name"
                />

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

                <div v-if="termsRequired" class="flex flex-col gap-1">
                    <label class="flex items-start gap-2 text-sm text-ink">
                        <input
                            v-model="form.terms"
                            type="checkbox"
                            name="terms"
                            class="mt-0.5 rounded-sm border-border"
                        >
                        <span>
                            {{ $t('I agree to the') }}
                            <Link href="/legal/terms" target="_blank" class="text-brand-600 hover:text-brand-700">{{ $t('Terms of Service') }}</Link>
                            {{ $t('and') }}
                            <Link href="/legal/privacy" target="_blank" class="text-brand-600 hover:text-brand-700">{{ $t('Privacy Policy') }}</Link>
                        </span>
                    </label>
                    <span v-if="form.errors.terms" class="text-xs text-red-600">{{ form.errors.terms }}</span>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600 disabled:opacity-50"
                >
                    {{ $t('Create account') }}
                </button>
            </form>

        <template #footer>
            {{ $t('Already have an account?') }}
            <Link href="/login" class="text-brand-600 hover:text-brand-700">{{ $t('Sign in') }}</Link>
        </template>
    </AuthScreen>
</template>
