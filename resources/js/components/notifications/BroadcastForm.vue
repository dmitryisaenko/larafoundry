<script setup>
/**
 * Create / edit a super-admin broadcast (phase 4.1).
 *
 * `notification` is null when creating; otherwise it carries the draft's current
 * values. The operator types per-locale text, picks an audience filter and an
 * optional visibility window. The server is the authority: it re-validates the
 * type against the registry, requires the English title, refuses to edit a
 * non-draft, and resolves the audience itself when sending.
 */
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    notification: { type: Object, default: null },
    types: { type: Array, default: () => [] },
    availableLocales: { type: Array, default: () => ['en'] },
});

const isEdit = !!props.notification;

function localeMap(source) {
    return Object.fromEntries(props.availableLocales.map((locale) => [locale, source?.[locale] ?? '']));
}

const form = useForm({
    code: props.notification?.code ?? props.types[0] ?? 'info',
    title_translations: localeMap(props.notification?.title_translations),
    body_translations: localeMap(props.notification?.body_translations),
    recipients: {
        emailVerified: props.notification?.recipients?.emailVerified ?? '',
        recentActivity: props.notification?.recipients?.recentActivity ?? '',
        role: props.notification?.recipients?.role ?? '',
    },
    visible_from: props.notification?.visible_from ?? '',
    visible_until: props.notification?.visible_until ?? '',
});

const fieldClass = 'w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink';

function submit() {
    if (isEdit) {
        form.put(`/admin/notifications/${props.notification.id}`);
    } else {
        form.post('/admin/notifications');
    }
}
</script>

<template>
    <form class="mx-auto w-full max-w-2xl space-y-6" @submit.prevent="submit">
        <h1 class="text-xl font-semibold text-ink">
            {{ isEdit ? $t('Edit broadcast') : $t('New broadcast') }}
        </h1>

        <div>
            <label class="mb-1 block text-sm font-medium text-ink">{{ $t('Type') }}</label>
            <select v-model="form.code" :class="fieldClass">
                <option v-for="type in types" :key="type" :value="type">{{ $t(type) }}</option>
            </select>
        </div>

        <div v-for="locale in availableLocales" :key="locale" class="space-y-2 rounded-sm border border-border p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-soft">{{ locale }}</p>

            <div>
                <label class="mb-1 block text-sm font-medium text-ink">
                    {{ $t('Title') }}<span v-if="locale === 'en'" class="text-danger"> *</span>
                </label>
                <input v-model="form.title_translations[locale]" type="text" :class="fieldClass">
                <p v-if="form.errors[`title_translations.${locale}`]" class="mt-1 text-xs text-danger">
                    {{ form.errors[`title_translations.${locale}`] }}
                </p>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-ink">{{ $t('Message') }}</label>
                <textarea v-model="form.body_translations[locale]" rows="3" :class="fieldClass" />
                <p v-if="form.errors[`body_translations.${locale}`]" class="mt-1 text-xs text-danger">
                    {{ form.errors[`body_translations.${locale}`] }}
                </p>
            </div>
        </div>

        <fieldset class="space-y-3 rounded-sm border border-border p-3">
            <legend class="px-1 text-sm font-medium text-ink">{{ $t('Audience') }}</legend>

            <div>
                <label class="mb-1 block text-sm text-ink-soft">{{ $t('Email verification') }}</label>
                <select v-model="form.recipients.emailVerified" :class="fieldClass">
                    <option value="">{{ $t('All users') }}</option>
                    <option value="verified">{{ $t('Verified') }}</option>
                    <option value="unverified">{{ $t('Unverified') }}</option>
                </select>
            </div>

            <div>
                <label class="mb-1 block text-sm text-ink-soft">{{ $t('Active recently') }}</label>
                <select v-model="form.recipients.recentActivity" :class="fieldClass">
                    <option value="">{{ $t('Any') }}</option>
                    <option value="1">{{ $t('Last hour') }}</option>
                    <option value="24">{{ $t('Last 24 hours') }}</option>
                    <option value="168">{{ $t('Last 7 days') }}</option>
                </select>
            </div>

            <div>
                <label class="mb-1 block text-sm text-ink-soft">{{ $t('Role') }}</label>
                <input v-model="form.recipients.role" type="text" :class="fieldClass" :placeholder="$t('Any role')">
            </div>
        </fieldset>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-medium text-ink">{{ $t('Visible from') }}</label>
                <input v-model="form.visible_from" type="datetime-local" :class="fieldClass">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-ink">{{ $t('Visible until') }}</label>
                <input v-model="form.visible_until" type="datetime-local" :class="fieldClass">
                <p v-if="form.errors.visible_until" class="mt-1 text-xs text-danger">{{ form.errors.visible_until }}</p>
            </div>
        </div>

        <button
            type="submit"
            :disabled="form.processing"
            class="rounded-sm bg-brand-500 px-4 py-2 text-white transition hover:bg-brand-600 disabled:opacity-50"
        >
            {{ $t('Save') }}
        </button>
    </form>
</template>
