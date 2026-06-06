<script setup>
/**
 * Profile details form (phase 5.1).
 *
 * Posts to Fortify's profile-information endpoint, which the core's
 * UpdateUserProfileInformation action backs. Changing the email is sensitive:
 * the current password is required, the address must be re-verified and other
 * devices are signed out — so the password field only appears once the email is
 * actually edited.
 */
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { InputField, SelectField, DateField } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    profile: { type: Object, required: true },
});

const form = useForm({
    name: props.profile.name ?? '',
    lastname: props.profile.lastname ?? '',
    middlename: props.profile.middlename ?? '',
    email: props.profile.email ?? '',
    phone: props.profile.phone ?? '',
    country: props.profile.country ?? '',
    sex: props.profile.sex ?? '',
    birth_date: props.profile.birth_date ?? '',
    current_password: '',
});

const emailChanged = computed(
    () => (form.email ?? '').trim().toLowerCase() !== (props.profile.email ?? '').trim().toLowerCase(),
);

const sexOptions = [
    { value: 'm', name: 'Male' },
    { value: 'f', name: 'Female' },
];

function submit() {
    form.put('/user/profile-information', {
        preserveScroll: true,
        onSuccess: () => form.reset('current_password'),
    });
}
</script>

<template>
    <form class="flex max-w-xl flex-col gap-4" @submit.prevent="submit">
        <div class="grid gap-4 sm:grid-cols-2">
            <InputField v-model="form.name" name="name" :title="$t('Name')" :errors="form.errors" required />
            <InputField v-model="form.lastname" name="lastname" :title="$t('Last name')" :errors="form.errors" />
        </div>

        <InputField v-model="form.middlename" name="middlename" :title="$t('Middle name')" :errors="form.errors" />

        <InputField
            v-model="form.email"
            name="email"
            type="email"
            :title="$t('Email')"
            :errors="form.errors"
            autocomplete="email"
            required
        />

        <p v-if="!profile.email_verified" class="-mt-2 text-xs text-warning">
            {{ $t('Your email address is not verified.') }}
        </p>

        <InputField
            v-if="emailChanged"
            v-model="form.current_password"
            name="current_password"
            type="password"
            :title="$t('Current password')"
            :placeholder="$t('Required to change your email')"
            :errors="form.errors"
            autocomplete="current-password"
        />

        <div class="grid gap-4 sm:grid-cols-2">
            <InputField v-model="form.phone" name="phone" :title="$t('Phone')" :errors="form.errors" />
            <InputField v-model="form.country" name="country" :title="$t('Country')" :errors="form.errors" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <SelectField
                v-model="form.sex"
                name="sex"
                :title="$t('Gender')"
                :value-name-array="sexOptions"
                :default-name="$t('Not specified')"
                :errors="form.errors"
                translate
            />
            <DateField v-model="form.birth_date" name="birth_date" :title="$t('Date of birth')" :errors="form.errors" />
        </div>

        <div>
            <button
                type="submit"
                class="rounded-sm bg-brand-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-800 disabled:opacity-50"
                :disabled="form.processing"
            >
                {{ $t('Save') }}
            </button>
        </div>
    </form>
</template>
