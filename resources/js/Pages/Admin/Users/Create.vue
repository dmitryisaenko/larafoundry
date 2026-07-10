<script setup>
import { useForm } from '@inertiajs/vue3';
import { AdminLayout, InputField, SelectField, SocialLinksField } from '@dmitryisaenko/larafoundry';

/**
 * Super-admin create-user form (phase 2.3, extended 3b). Super-admin only.
 *
 * Creates a user with a profile and an optional admin flag. The server hashes
 * the password (model `password => hashed` cast) and refuses to mass-assign the
 * privilege/state columns. The personal fields (sex/birth_date) and the social
 * widget are gated on the same opt-in tokens the user list uses (`userColumns`),
 * so a privacy-clean install does not surface them.
 */
const props = defineProps({
    // Opt-in column tokens the operator enabled (phase 3a/3b): 'sex', 'age',
    // 'social' decide which gated fields render. 'phone'/middlename are always on.
    userColumns: { type: Array, default: () => [] },
    // The recognised social platforms (config-driven), passed to the widget.
    socialPlatforms: { type: Array, default: () => [] },
});

function has(token) {
    return props.userColumns.includes(token);
}

const form = useForm({
    name: '',
    lastname: '',
    middlename: '',
    email: '',
    phone: '',
    country: '',
    sex: '',
    birth_date: '',
    password: '',
    password_confirmation: '',
    is_admin: false,
    social_links: [],
});

const sexOptions = [
    { value: 'm', name: 'Male' },
    { value: 'f', name: 'Female' },
];

function submit() {
    form.post('/admin/users');
}
</script>

<template>
    <AdminLayout :title="$t('New user')">
        <form class="max-w-xl space-y-4 rounded-sm border border-border bg-surface p-6" @submit.prevent="submit">
            <InputField v-model="form.name" name="name" :title="$t('Name')" :errors="form.errors" required />
            <InputField v-model="form.lastname" name="lastname" :title="$t('Last name')" :errors="form.errors" />
            <InputField v-model="form.middlename" name="middlename" :title="$t('Middle name')" :errors="form.errors" />
            <InputField v-model="form.email" name="email" type="email" :title="$t('Email')" :errors="form.errors" required />
            <InputField v-model="form.phone" name="phone" :title="$t('Phone')" :errors="form.errors" />
            <InputField v-model="form.country" name="country" :title="$t('Country')" :errors="form.errors" />

            <SelectField
                v-if="has('sex')"
                v-model="form.sex"
                name="sex"
                :title="$t('Gender')"
                :value-name-array="sexOptions"
                :default-name="$t('Not specified')"
                :errors="form.errors"
                translate
            />

            <InputField
                v-if="has('age')"
                v-model="form.birth_date"
                name="birth_date"
                type="date"
                :title="$t('Birth date')"
                :errors="form.errors"
            />

            <InputField
                v-model="form.password"
                name="password"
                type="password"
                :title="$t('Password')"
                :errors="form.errors"
                required
                autocomplete="new-password"
            />
            <InputField
                v-model="form.password_confirmation"
                name="password_confirmation"
                type="password"
                :title="$t('Confirm password')"
                :errors="form.errors"
                required
                autocomplete="new-password"
            />

            <SocialLinksField
                v-if="has('social')"
                v-model="form.social_links"
                :platforms="socialPlatforms"
                :errors="form.errors"
            />

            <label class="flex items-center gap-2 text-sm text-ink">
                <input v-model="form.is_admin" type="checkbox" class="rounded-sm border-border">
                {{ $t('Super-admin') }}
            </label>

            <div class="flex justify-end">
                <button
                    type="submit"
                    class="rounded-sm bg-brand-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-800 disabled:opacity-50"
                    :disabled="form.processing"
                >
                    {{ $t('Create') }}
                </button>
            </div>
        </form>
    </AdminLayout>
</template>
