<script setup>
/**
 * Change password (phase 5.1).
 *
 * Posts to Fortify's password endpoint, backed by the core's UpdateUserPassword
 * action (current password required, Password::defaults() strength). Hidden for
 * OAuth-only users, who have no local password to change.
 */
import { useForm } from '@inertiajs/vue3';
import { InputField } from '@dmitryisaenko/larafoundry';

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.put('/user/password', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => form.reset('current_password', 'password', 'password_confirmation'),
    });
}
</script>

<template>
    <section class="flex max-w-xl flex-col gap-4">
        <header>
            <h2 class="text-base font-semibold text-ink">{{ $t('Change password') }}</h2>
            <p class="text-sm text-ink-soft">{{ $t('Use a long, unique password to keep your account secure.') }}</p>
        </header>

        <form class="flex flex-col gap-3" @submit.prevent="submit">
            <InputField
                v-model="form.current_password"
                name="current_password"
                type="password"
                :title="$t('Current password')"
                :errors="form.errors"
                autocomplete="current-password"
            />
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
                :title="$t('Confirm new password')"
                :errors="form.errors"
                autocomplete="new-password"
            />

            <div>
                <button
                    type="submit"
                    class="rounded-sm bg-brand-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-800 disabled:opacity-50"
                    :disabled="form.processing"
                >
                    {{ $t('Update password') }}
                </button>
            </div>
        </form>
    </section>
</template>
