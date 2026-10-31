<script setup>
/**
 * Set a first password (v0.49.0).
 *
 * Shown instead of the change-password form to an account that has NO password -
 * one created through OAuth - so it can also sign in with e-mail and password.
 * There is no current-password field (there is none to give); the server refuses
 * this endpoint to an account that already has a password, so it cannot bypass
 * the change flow's check.
 *
 * On success the hub reloads with `has_password` true and shows the change form.
 */
import { useForm } from '@inertiajs/vue3';
import { InputField } from '@dmitryisaenko/larafoundry';

const form = useForm({
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/profile/set-password', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <section class="flex max-w-xl flex-col gap-4">
        <header>
            <h2 class="text-base font-semibold text-ink">{{ $t('Set a password') }}</h2>
            <p class="text-sm text-ink-soft">{{ $t('Add a password so you can also sign in with your email.') }}</p>
        </header>

        <form class="flex flex-col gap-3" @submit.prevent="submit">
            <InputField
                v-model="form.password"
                name="password"
                type="password"
                :title="$t('New password')"
                :errors="form.errors"
                autocomplete="new-password"
            />
            <InputField
                v-model="form.password_confirmation"
                name="password_confirmation"
                type="password"
                :title="$t('Confirm password')"
                :errors="form.errors"
                autocomplete="new-password"
            />

            <div>
                <button
                    type="submit"
                    class="rounded-sm bg-brand-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-800 disabled:opacity-50"
                    :disabled="form.processing"
                >
                    {{ $t('Set password') }}
                </button>
            </div>
        </form>
    </section>
</template>
