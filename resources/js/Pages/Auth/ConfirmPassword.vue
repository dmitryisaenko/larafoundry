<script setup>
/**
 * Password-confirmation page.
 *
 * Guards sensitive areas: the user re-enters their password, which is POSTed
 * to Laravel Fortify's `/user/confirm-password` endpoint. On success Fortify
 * marks the session confirmed and redirects to the intended destination.
 */
import { useForm } from '@inertiajs/vue3';
import InputField from '../../components/ui/InputField.vue';
import AuthCard from '../../components/AuthCard.vue';
import AppBaseLayout from '../../layouts/AppBaseLayout.vue';

const form = useForm({
    password: '',
});

function submit() {
    form.post('/user/confirm-password', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <AppBaseLayout>
        <AuthCard
            :title="$t('Confirm password')"
            :subtitle="$t('This is a secure area. Please confirm your password before continuing.')"
        >
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <InputField
                    v-model="form.password"
                    :title="$t('Password')"
                    name="password"
                    type="password"
                    :required="true"
                    :errors="form.errors"
                    autocomplete="current-password"
                />

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600 disabled:opacity-50"
                >
                    {{ $t('Confirm') }}
                </button>
            </form>
        </AuthCard>
    </AppBaseLayout>
</template>
