<script setup>
/**
 * The legal-page editor (phase 5.3), super-admin only.
 *
 * Edits one page's title/body per locale (a tab per available locale), previews a
 * server-sanitized render in a sandboxed iframe, and publishes. The body field is
 * a plain textarea — the operator pastes HTML; the value is sanitized server-side
 * on save and on preview, so no rich-text editor is needed (and none of its XSS
 * surface). Mirrors the email-template editor, minus the variable list and the
 * test-email send (legal pages are static, with no `{{token}}` substitution).
 *
 * `is_published` controls public visibility; "require re-acceptance" bumps the
 * version, which the terms re-accept gate (phase 5.3 part B) compares against the
 * version each user has accepted.
 */
import { reactive, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { InputField, TextareaField, EmailPreviewFrame } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    page: { type: Object, required: true },
    locales: { type: Array, default: () => [] },
});

const activeLocale = ref(props.locales[0] ?? 'en');

// Seed the per-locale maps from the resolved page, guaranteeing a key for every
// available locale so each tab has a bound field.
function localeMap(source) {
    const map = {};
    for (const locale of props.locales) {
        map[locale] = source?.[locale] ?? '';
    }
    return map;
}

const form = useForm({
    title: localeMap(props.page.title),
    body_html: localeMap(props.page.body_html),
    is_published: props.page.is_published,
    bump_version: false,
});

function save() {
    form.put(`/admin/legal-pages/${props.page.slug}`, { preserveScroll: true });
}

// --- Preview (server-sanitized) ---------------------------------------------

const preview = reactive({ html: '', open: false });

function xsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function runPreview() {
    const response = await fetch(`/admin/legal-pages/${props.page.slug}/preview`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify({
            body_html: form.body_html[activeLocale.value],
        }),
    });

    if (!response.ok) {
        return;
    }

    const data = await response.json();
    preview.html = data.html;
    preview.open = true;
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <!-- Publish state + current version -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <label class="flex items-center gap-2 text-sm text-ink">
                <input v-model="form.is_published" type="checkbox" class="rounded-sm border-border">
                {{ $t('Page is published (publicly visible)') }}
            </label>
            <span class="text-xs text-ink-soft">{{ $t('Version') }}: {{ page.version }}</span>
        </div>

        <label class="flex items-center gap-2 text-sm text-ink">
            <input v-model="form.bump_version" type="checkbox" class="rounded-sm border-border">
            {{ $t('This change requires users to re-accept (bump version)') }}
        </label>

        <!-- Locale tabs -->
        <div class="flex gap-1 border-b border-border">
            <button
                v-for="locale in locales"
                :key="locale"
                type="button"
                class="border-b-2 px-3 py-2 text-sm"
                :class="locale === activeLocale ? 'border-primary text-ink' : 'border-transparent text-ink-soft'"
                @click="activeLocale = locale"
            >
                {{ locale.toUpperCase() }}
            </button>
        </div>

        <!-- Per-locale fields. Field `name` is the dot-path so it matches the
             Laravel validation error key (e.g. `title.en`). -->
        <div class="flex flex-col gap-4">
            <InputField
                v-model="form.title[activeLocale]"
                :name="`title.${activeLocale}`"
                :title="$t('Title')"
                :errors="form.errors"
            />
            <TextareaField
                v-model="form.body_html[activeLocale]"
                :name="`body_html.${activeLocale}`"
                :title="$t('Body (HTML)')"
                :rows="16"
                :errors="form.errors"
            />
        </div>

        <!-- Actions -->
        <div class="flex flex-wrap items-center gap-3">
            <button
                type="button"
                class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                :disabled="form.processing"
                @click="save"
            >
                {{ $t('Save page') }}
            </button>
            <button
                type="button"
                class="rounded-md border border-border px-4 py-2 text-sm font-medium text-ink"
                @click="runPreview"
            >
                {{ $t('Preview') }}
            </button>
        </div>

        <!-- Preview panel -->
        <div v-if="preview.open" class="flex flex-col gap-2">
            <span class="text-sm font-medium text-ink">{{ $t('Preview') }}</span>
            <EmailPreviewFrame :html="preview.html" :title="$t('Legal page preview')" />
        </div>
    </div>
</template>
