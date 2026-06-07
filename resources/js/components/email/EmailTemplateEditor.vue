<script setup>
/**
 * The email-template editor (phase 5.1), super-admin only.
 *
 * Edits one template's subject/body per locale (a tab per available locale),
 * lists the variables the template allows, previews a server-rendered+purified
 * sample in a sandboxed iframe, and sends a test email. The body fields are plain
 * textareas — the operator pastes HTML; the value is purified server-side on save
 * and on preview, so no rich-text editor is needed (and none of its XSS surface).
 *
 * Saving PUTs the whole per-locale maps; the backend re-validates every
 * referenced `{{variable}}` against the template's whitelist (STRICT) and
 * purifies each html body before storage.
 */
import { reactive, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { InputField, TextareaField, SelectField, EmailPreviewFrame } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    template: { type: Object, required: true },
    locales: { type: Array, default: () => [] },
});

const activeLocale = ref(props.locales[0] ?? 'en');

// Seed the per-locale maps from the resolved template, guaranteeing a key for
// every available locale so each tab has a bound field.
function localeMap(source) {
    const map = {};
    for (const locale of props.locales) {
        map[locale] = source?.[locale] ?? '';
    }
    return map;
}

const form = useForm({
    is_active: props.template.is_active,
    subject: localeMap(props.template.subject),
    body_html: localeMap(props.template.body_html),
    body_text: localeMap(props.template.body_text),
});

function save() {
    form.put(`/admin/email-templates/${props.template.code}`, { preserveScroll: true });
}

// --- Preview (server-rendered + server-purified) ----------------------------

const preview = reactive({ subject: '', html: '', open: false });

function xsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function runPreview() {
    const response = await fetch(`/admin/email-templates/${props.template.code}/preview`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify({
            locale: activeLocale.value,
            subject: form.subject[activeLocale.value],
            body_html: form.body_html[activeLocale.value],
            body_text: form.body_text[activeLocale.value],
        }),
    });

    if (!response.ok) {
        return;
    }

    const data = await response.json();
    preview.subject = data.subject;
    preview.html = data.html;
    preview.open = true;
}

// --- Test email -------------------------------------------------------------

const testForm = useForm({ email: '', locale: activeLocale.value });

function sendTest() {
    testForm
        .transform((data) => ({ ...data, locale: activeLocale.value }))
        .post(`/admin/email-templates/${props.template.code}/test`, {
            preserveScroll: true,
            onSuccess: () => testForm.reset('email'),
        });
}

function localeOptions() {
    return props.locales.map((locale) => ({ value: locale, name: locale.toUpperCase() }));
}

// Build the `{{name}}` token without putting literal braces in the template
// markup (Vue's parser would read them as an interpolation).
function placeholder(variable) {
    return '{{' + variable + '}}';
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <!-- Active state -->
        <label class="flex items-center gap-2 text-sm text-ink">
            <input v-model="form.is_active" type="checkbox" class="rounded-sm border-border">
            {{ $t('Template is active') }}
        </label>

        <!-- Available variables -->
        <div v-if="template.variables.length" class="flex flex-col gap-2">
            <span class="text-sm font-medium text-ink">{{ $t('Available variables') }}</span>
            <div class="flex flex-wrap gap-2">
                <code
                    v-for="variable in template.variables"
                    :key="variable"
                    class="rounded bg-surface-muted px-2 py-1 text-xs text-ink-soft"
                >{{ placeholder(variable) }}</code>
            </div>
        </div>

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
             Laravel validation error key (e.g. `subject.en`). -->
        <div class="flex flex-col gap-4">
            <InputField
                v-model="form.subject[activeLocale]"
                :name="`subject.${activeLocale}`"
                :title="$t('Subject')"
                :errors="form.errors"
            />
            <TextareaField
                v-model="form.body_html[activeLocale]"
                :name="`body_html.${activeLocale}`"
                :title="$t('HTML body')"
                :rows="10"
                :errors="form.errors"
            />
            <TextareaField
                v-model="form.body_text[activeLocale]"
                :name="`body_text.${activeLocale}`"
                :title="$t('Plain-text body')"
                :rows="6"
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
                {{ $t('Save template') }}
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
            <span class="text-sm font-medium text-ink">{{ $t('Preview') }}: {{ preview.subject }}</span>
            <EmailPreviewFrame :html="preview.html" />
        </div>

        <!-- Test email -->
        <div class="flex flex-col gap-3 border-t border-border pt-6">
            <span class="text-sm font-medium text-ink">{{ $t('Send a test email') }}</span>
            <div class="flex flex-wrap items-end gap-3">
                <InputField
                    v-model="testForm.email"
                    name="email"
                    type="email"
                    :title="$t('Recipient address')"
                    :errors="testForm.errors"
                />
                <SelectField
                    :model-value="activeLocale"
                    name="test_locale"
                    :title="$t('Locale')"
                    :value-name-array="localeOptions()"
                    @update:model-value="(value) => (activeLocale = value)"
                />
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-ink disabled:opacity-50"
                    :disabled="testForm.processing"
                    @click="sendTest"
                >
                    {{ $t('Send test') }}
                </button>
            </div>
        </div>
    </div>
</template>
