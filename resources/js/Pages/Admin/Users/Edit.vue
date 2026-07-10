<script setup>
import { router, useForm } from '@inertiajs/vue3';
import { AdminLayout, InputField, SelectField, SocialLinksField, confirm, useT } from '@dmitryisaenko/larafoundry';

const t = useT();

/**
 * Super-admin edit form for one user (phase 2.3, extended 3b). Super-admin only.
 *
 * Edits profile fields and the admin flag; password is optional (left blank to
 * keep it). Blocking/deletion are not done here — they are row actions on the
 * list. The verify-manage block force-verifies or clears the user's email/phone
 * through the existing phase-3a endpoints (no mail/SMS is sent) — a destructive
 * "cancel verification" goes through the core confirm dialog, never a native
 * window.confirm. Gated fields (sex/age/social) follow the same opt-in tokens
 * the list uses; the resource always carries the values via full().
 */
const props = defineProps({
    user: { type: Object, required: true },
    userColumns: { type: Array, default: () => [] },
    socialPlatforms: { type: Array, default: () => [] },
});

const data = props.user.data ?? props.user;

function has(token) {
    return props.userColumns.includes(token);
}

const form = useForm({
    name: data.name ?? '',
    lastname: data.lastname ?? '',
    middlename: data.middlename ?? '',
    email: data.email ?? '',
    phone: data.phone ?? '',
    country: data.country ?? '',
    sex: data.sex ?? '',
    birth_date: data.birth_date ?? '',
    password: '',
    password_confirmation: '',
    is_admin: data.is_admin ?? false,
    // Prefilled from the resource (full() always carries them for the edit view);
    // mapped to the widget's {platform, url} shape.
    social_links: (data.social_links ?? []).map((link) => ({ platform: link.platform, url: link.url })),
});

const sexOptions = [
    { value: 'm', name: 'Male' },
    { value: 'f', name: 'Female' },
];

function submit() {
    form.put(`/admin/users/${data.id}`, { preserveScroll: true });
}

// --- Verify-manage (reuses the phase-3a endpoints; no mail/SMS is sent) ---

function verifyEmail() {
    router.post(`/admin/users/${data.id}/verify-email`, {}, { preserveScroll: true });
}

async function unverifyEmail() {
    const ok = await confirm({
        variant: 'warning',
        title: t('Cancel email verification'),
        message: t("This clears the user's email verification."),
        confirmLabel: t('Cancel email verification'),
    });
    if (ok) {
        router.post(`/admin/users/${data.id}/unverify-email`, {}, { preserveScroll: true });
    }
}

function verifyPhone() {
    router.post(`/admin/users/${data.id}/verify-phone`, {}, { preserveScroll: true });
}

async function unverifyPhone() {
    const ok = await confirm({
        variant: 'warning',
        title: t('Cancel phone verification'),
        message: t("This clears the user's phone verification."),
        confirmLabel: t('Cancel phone verification'),
    });
    if (ok) {
        router.post(`/admin/users/${data.id}/unverify-phone`, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <AdminLayout :title="$t('Edit user')">
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
                :title="$t('New password')"
                :placeholder="$t('Leave blank to keep current')"
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
                    {{ $t('Save') }}
                </button>
            </div>
        </form>

        <!-- Verify-manage: email + phone force/cancel (no mail or SMS is sent). -->
        <div class="mt-4 max-w-xl space-y-4 rounded-sm border border-border bg-surface p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="text-sm">
                    <div class="font-medium text-ink">{{ $t('Email') }}</div>
                    <div v-if="data.email_verified" class="text-xs text-emerald-600">{{ $t('Email verified') }}</div>
                    <div v-else class="text-xs text-ink-soft">{{ $t('Not verified') }}</div>
                </div>
                <button
                    v-if="!data.email_verified"
                    type="button"
                    class="rounded-sm border border-border px-3 py-2 text-sm font-medium text-ink transition hover:bg-surface-subtle"
                    @click="verifyEmail"
                >
                    {{ $t('Verify email') }}
                </button>
                <button
                    v-else
                    type="button"
                    class="rounded-sm border border-border px-3 py-2 text-sm font-medium text-warning transition hover:bg-surface-subtle"
                    @click="unverifyEmail"
                >
                    {{ $t('Cancel email verification') }}
                </button>
            </div>

            <div v-if="data.phone" class="flex flex-wrap items-center justify-between gap-3">
                <div class="text-sm">
                    <div class="font-medium text-ink">{{ $t('Phone') }}</div>
                    <div v-if="data.phone_verified" class="text-xs text-emerald-600">{{ $t('Verified') }}</div>
                    <div v-else class="text-xs text-ink-soft">{{ $t('Not verified') }}</div>
                </div>
                <button
                    v-if="!data.phone_verified"
                    type="button"
                    class="rounded-sm border border-border px-3 py-2 text-sm font-medium text-ink transition hover:bg-surface-subtle"
                    @click="verifyPhone"
                >
                    {{ $t('Verify phone') }}
                </button>
                <button
                    v-else
                    type="button"
                    class="rounded-sm border border-border px-3 py-2 text-sm font-medium text-warning transition hover:bg-surface-subtle"
                    @click="unverifyPhone"
                >
                    {{ $t('Cancel phone verification') }}
                </button>
            </div>
        </div>
    </AdminLayout>
</template>
