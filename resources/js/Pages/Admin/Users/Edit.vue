<script setup>
import { useForm } from '@inertiajs/vue3';
import { AdminLayout, InputField } from '@dmitryisaenko/larafoundry';

/**
 * Super-admin edit form for one user (phase 2.3). Super-admin only.
 *
 * Edits profile fields and the admin flag; password is optional (left blank to
 * keep it). Blocking/deletion are not done here — they are row actions on the
 * list (each with its own confirmation and audit trail).
 */
const props = defineProps({
    user: { type: Object, required: true },
});

const data = props.user.data ?? props.user;

const form = useForm({
    name: data.name ?? '',
    lastname: data.lastname ?? '',
    email: data.email ?? '',
    phone: data.phone ?? '',
    country: data.country ?? '',
    password: '',
    is_admin: data.is_admin ?? false,
});

function submit() {
    form.put(`/admin/users/${data.id}`, { preserveScroll: true });
}
</script>

<template>
    <AdminLayout :title="$t('Edit user')">
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
                :title="$t('New password')"
                :placeholder="$t('Leave blank to keep current')"
                :errors="form.errors"
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
                    {{ $t('Save') }}
                </button>
            </div>
        </form>
    </AdminLayout>
</template>
