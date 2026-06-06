<script setup>
/**
 * Registration page.
 *
 * POSTs the new account fields to Laravel Fortify's `/register` endpoint.
 * On success Fortify logs the user in and redirects.
 */
import { ref } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { InputField, AuthScreen } from '@dmitryisaenko/larafoundry';

// Modal-mode visibility (inert in page mode); closed on a successful register.
const open = ref(true);

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
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
