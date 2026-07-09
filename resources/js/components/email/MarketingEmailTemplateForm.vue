<script setup>
/**
 * The marketing email-template form (phase 2b), super-admin only.
 *
 * Drives BOTH create and marketing-edit (the transactional editor stays the
 * separate, leaner {@link EmailTemplateEditor}). A marketing template is
 * self-contained: it owns a `name`, an editable `code` (a slug auto-derived from
 * the name on create, fixed on edit), its OWN `{{variable}}` whitelist, and the
 * per-locale subject/body. The body is a plain textarea — the operator pastes
 * HTML; it is purified server-side on save and on preview, so there is no
 * rich-text XSS surface.
 *
 * Preview renders UNSAVED input server-side (sample data derived from the current
 * variable list) in a sandboxed iframe. Test-send and Delete need a persisted row,
 * so they show only in edit mode.
 */
import { reactive, ref, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import {
    InputField,
    TextareaField,
    SelectField,
    EmailPreviewFrame,
    confirm,
    useT,
} from '@dmitryisaenko/larafoundry';

const props = defineProps({
    template: { type: Object, default: null },
    locales: { type: Array, default: () => [] },
    mode: { type: String, default: 'create' }, // 'create' | 'edit'
});

const t = useT();
const isEdit = props.mode === 'edit';
const activeLocale = ref(props.locales[0] ?? 'en');

// Seed a per-locale map, guaranteeing a key for every available locale.
function localeMap(source) {
    const map = {};
    for (const locale of props.locales) {
        map[locale] = source?.[locale] ?? '';
    }
    return map;
}

const form = useForm({
    code: props.template?.code ?? '',
    name: props.template?.name ?? '',
    is_active: props.template?.is_active ?? true,
    variables: [...(props.template?.variables ?? [])],
    subject: localeMap(props.template?.subject),
    body_html: localeMap(props.template?.body_html),
    body_text: localeMap(props.template?.body_text),
});

// --- Code auto-slug (create only) -------------------------------------------

// Once the operator hand-edits the code, stop overwriting it from the name.
const codeTouched = ref(isEdit);

// Produce a code that always matches the server rule `^[a-z][a-z0-9_]*$` (must
// start with a lowercase letter), or an empty string so the operator types one.
// A leading digit/symbol run is STRIPPED (never prepended with `_`), so the UI
// never auto-fills a code the server would 422-reject.
function slugify(value) {
    return String(value)
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .replace(/^[^a-z]+/, '');
}

// Exposed for unit testing (the component keeps slugify private otherwise).
defineExpose({ slugify });

watch(
    () => form.name,
    (name) => {
        if (!isEdit && !codeTouched.value) {
            form.code = slugify(name);
        }
    },
);

// --- Variables editor -------------------------------------------------------

function addVariable() {
    form.variables.push('');
}

function removeVariable(index) {
    form.variables.splice(index, 1);
}

function placeholder(variable) {
    return '{{' + variable + '}}';
}

// --- Submit -----------------------------------------------------------------

function submit() {
    // Drop empty/blank variable tokens before sending.
    form
        .transform((data) => ({
            ...data,
            variables: data.variables.map((v) => v.trim()).filter((v) => v.length > 0),
        }))
        .submit(
            isEdit ? 'put' : 'post',
            isEdit ? `/admin/email-templates/${props.template.code}` : '/admin/email-templates',
            { preserveScroll: true },
        );
}

async function destroy() {
    const ok = await confirm({
        variant: 'danger',
        title: t('Delete template'),
        message: t('This marketing template will be permanently deleted.'),
        highlight: form.name || props.template.code,
        confirmLabel: t('Delete'),
    });

    if (ok) {
        router.delete(`/admin/email-templates/${props.template.code}`);
    }
}

// --- Preview (server-rendered + server-purified) ----------------------------

const preview = reactive({ subject: '', html: '', open: false });

function xsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function runPreview() {
    const response = await fetch('/admin/email-templates/preview-draft', {
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
            variables: form.variables.map((v) => v.trim()).filter((v) => v.length > 0),
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

// --- Test email (edit only — needs a persisted code) ------------------------

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
</script>

<template>
    <div class="flex flex-col gap-6">
        <!-- Name + code -->
        <div class="grid gap-4 sm:grid-cols-2">
            <InputField
                v-model="form.name"
                name="name"
                :title="$t('Template name')"
                :errors="form.errors"
            />
            <InputField
                v-model="form.code"
                name="code"
                :title="$t('Code')"
                :readonly="isEdit"
                :errors="form.errors"
                @input="codeTouched = true"
            />
        </div>

        <!-- Active state -->
        <label class="flex items-center gap-2 text-sm text-ink">
            <input v-model="form.is_active" type="checkbox" class="rounded-sm border-border">
            {{ $t('Template is active') }}
        </label>

        <!-- Variables editor -->
        <div class="flex flex-col gap-2">
            <span class="text-sm font-medium text-ink">{{ $t('Variables') }}</span>
            <p class="text-xs text-ink-soft">
                {{ $t('Only variables listed here may be used in the subject and body.') }}
            </p>
            <div v-for="(variable, index) in form.variables" :key="index" class="flex items-center gap-2">
                <code class="text-xs text-ink-soft">{{ placeholder(variable || 'name') }}</code>
                <input
                    v-model="form.variables[index]"
                    type="text"
                    class="rounded-md border border-border bg-surface px-2 py-1 text-sm text-ink"
                >
                <button
                    type="button"
                    class="text-xs font-medium text-danger hover:underline"
                    @click="removeVariable(index)"
                >
                    {{ $t('Remove') }}
                </button>
            </div>
            <div>
                <button
                    type="button"
                    class="rounded-md border border-border px-3 py-1.5 text-xs font-medium text-ink"
                    @click="addVariable"
                >
                    {{ $t('Add variable') }}
                </button>
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
                @click="submit"
            >
                {{ isEdit ? $t('Save template') : $t('Create template') }}
            </button>
            <button
                type="button"
                class="rounded-md border border-border px-4 py-2 text-sm font-medium text-ink"
                @click="runPreview"
            >
                {{ $t('Preview') }}
            </button>
            <button
                v-if="isEdit"
                type="button"
                class="rounded-md border border-danger px-4 py-2 text-sm font-medium text-danger"
                @click="destroy"
            >
                {{ $t('Delete') }}
            </button>
        </div>

        <!-- Preview panel -->
        <div v-if="preview.open" class="flex flex-col gap-2">
            <span class="text-sm font-medium text-ink">{{ $t('Preview') }}: {{ preview.subject }}</span>
            <EmailPreviewFrame :html="preview.html" />
        </div>

        <!-- Test email (edit only — a draft has no address to send from yet) -->
        <div v-if="isEdit" class="flex flex-col gap-3 border-t border-border pt-6">
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
        <p v-else class="text-xs text-ink-soft">
            {{ $t('Save the template to send a test email.') }}
        </p>
    </div>
</template>
