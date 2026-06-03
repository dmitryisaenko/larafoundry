<script setup>
import { useForm } from '@inertiajs/vue3';
import { AdminLayout, InputField } from '@dmitryisaenko/larafoundry';

/**
 * Super-admin create-user form (phase 2.3). Super-admin only.
 *
 * Creates a user with a profile and an optional admin flag. The server hashes
 * the password (model `password => hashed` cast) and refuses to mass-assign the
 * privilege/state columns.
 */
const form = useForm({
    name: '',
    lastname: '',
    email: '',
    phone: '',
    country: '',
    password: '',
    is_admin: false,
});

function submit() {
    form.post('/admin/users');
}
</script>

<template>
    <AdminLayout :title="$t('New user')">
        <form class="max-w-xl space-y-4 rounded-sm border border-border bg-surface p-6" @submit.prevent="submit">
            <InputField v-model="form.name" name="name" :title="$t('Name')" :errors="form.errors" required />
            <InputField v-model="form.lastname" name="lastname" :title="$t('Last name')" :errors="form.errors" />
            <InputField v-model="form.email" name="email" type="email" :title="$t('Email')" :errors="form.errors" required />
            <InputField v-model="form.phone" name="phone" :title="$t('Phone')" :errors="form.errors" />
            <InputField v-model="form.country" name="country" :title="$t('Country')" :errors="form.errors" />
            <InputField
                v-model="form.password"
                name="password"
                type="password"
                :title="$t('Password')"
                :errors="form.errors"
                required
                autocomplete="new-password"
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
