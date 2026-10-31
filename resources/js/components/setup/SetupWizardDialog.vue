<script setup>
/**
 * The first-login setup wizard, in the core's look (v0.49.0).
 *
 * Mounted by AppLayout while the shared `setup_wizard` prop is a list of steps.
 * WHEN it opens and WHICH steps it asks are the server's rule
 * (Setup\Support\SetupWizard); this only walks through them. A host with a shell
 * of its own draws its own dialog from the same prop and the same endpoints.
 *
 * An existing account is shown ITS values, not the defaults - theme, date format
 * and language from the shared props, the name and photo from `setup-wizard/state`.
 *
 * Every step writes through the endpoint that already keeps its setting (the
 * profile's ui-settings, the language switch, Fortify's profile update, the avatar
 * upload), so the wizard has no second copy of anybody's rules. Any step may be
 * left as it is.
 *
 * Three ways out: "Skip" (also Escape) - until the next login; "Don't show again"
 * and "Done" on the last step - for good. Each waits for the server, then reads
 * the prop again: it goes false and the layout unmounts the dialog, and a later
 * "Go through the setup again" mounts a fresh one.
 */
import { computed, onMounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import Modal from '../ui/Modal.vue';
import InputField from '../ui/InputField.vue';
import UserAvatar from '../media/UserAvatar.vue';
import ImageUpload from '../media/ImageUpload.vue';
import { formatDate } from '../../composables/dateFormat.js';
import { setupSteps, setupWizardCall } from './setupWizard.js';

const props = defineProps({
    /** The step keys the server asks over this page, in order. */
    steps: { type: Array, required: true },
});

const page = usePage();

const CORE_STEPS = {
    theme: 'Appearance',
    locale: 'Interface language',
    about: 'About you',
    date: 'Date format',
};

const THEMES = [
    { value: 'light', label: 'Light' },
    { value: 'dark', label: 'Dark' },
    { value: 'system', label: 'System' },
];

const DATE_FORMATS = ['auto', 'dmy', 'mdy', 'iso'];

// ⚠️ Read once: the layout mounts a fresh dialog each time the prop turns on, and
// a step must not vanish under the user while a save reloads the props.
// ⚠️ All or nothing: a step the server asks that this dialog cannot draw means the
// host draws its own wizard. Opening without it would let "Done" finish a part
// whose steps the user never saw.
const drawable = (key) => key in CORE_STEPS || key in setupSteps;
const shown = props.steps.every(drawable) ? [...props.steps] : [];

const step = ref(0);
const busy = ref(false);
const error = ref('');
const errors = ref({});
const state = ref({});
// Shut for good once a way out was saved, whatever the prop says next (review 2026-10-31).
const closed = ref(false);

const theme = ref(page.props.ui_settings?.theme ?? 'system');
const dateFormat = ref(page.props.ui_settings?.date_format ?? 'auto');
const name = ref('');
const lastname = ref('');
const avatar = ref(null);

const current = computed(() => shown[step.value]);
const last = computed(() => step.value === shown.length - 1);
const locales = computed(() => page.props.available_locales ?? []);
const title = computed(() => CORE_STEPS[current.value] ?? setupSteps[current.value]?.title ?? '');
const sample = new Date();

onMounted(async () => {
    if (!shown.length) return;

    try {
        state.value = await setupWizardCall('state');
        name.value = state.value.name ?? '';
        lastname.value = state.value.lastname ?? '';
    } catch {
        error.value = 'Could not load your settings. You can still skip the setup.';
    }
});

/**
 * A visit that keeps the dialog (and the page under it) where it is.
 *
 * ⚠️ Settled in `onFinish` too (review 2026-10-31): a response that is not Inertia's
 * (a photo over the upload limit, an expired session, a server error), a cancelled
 * visit or a dropped connection calls neither `onSuccess` nor `onError`, and the
 * dialog stood disabled with no way out but a reload.
 */
function visit(method, url, data, options = {}) {
    return new Promise((resolve, reject) => {
        let settled = false;

        router[method](url, data, {
            preserveScroll: true,
            preserveState: true,
            ...options,
            onSuccess: () => {
                settled = true;
                resolve();
            },
            onError: (bag) => {
                settled = true;
                reject(bag ?? {});
            },
            onFinish: () => {
                if (!settled) reject({});
            },
        });
    });
}

function saveUi(key, value) {
    return visit('put', '/profile/ui-settings', { key, value }, { only: ['ui_settings'] })
        .catch(() => {
            error.value = 'Could not save. Try again.';
        });
}

function pickTheme(value) {
    theme.value = value;
    saveUi('theme', value);
}

function pickDate(value) {
    dateFormat.value = value;
    saveUi('date_format', value);
}

/** The interface language: the whole page is drawn in it, so every prop is read again. */
function pickLocale(code) {
    if (code === page.props.locale) return;

    visit('post', route('larafoundry.language.switch'), { locale: code }).catch(() => {
        error.value = 'Could not save. Try again.';
    });
}

/**
 * The name only when it changed - the profile update also checks the e-mail,
 * which an operator's account may not be allowed to send back. Without the e-mail
 * (the state did not load) the update would read as an e-mail change, so the name
 * is not sent at all.
 */
async function saveAbout() {
    const renamed = name.value !== (state.value.name ?? '') || lastname.value !== (state.value.lastname ?? '');

    if (renamed && !state.value.email) {
        throw {};
    }

    if (renamed) {
        await visit('put', '/user/profile-information', {
            name: name.value,
            lastname: lastname.value,
            email: state.value.email ?? '',
        });
        state.value = { ...state.value, name: name.value, lastname: lastname.value };
    }

    if (avatar.value) {
        await visit('post', '/profile/avatar', { avatar: avatar.value }, { forceFormData: true });
        avatar.value = null;
    }
}

async function next() {
    error.value = '';
    errors.value = {};

    if (current.value === 'about') {
        busy.value = true;

        try {
            await saveAbout();
        } catch (bag) {
            errors.value = bag ?? {};
            // A refusal of a field this step does not show (the e-mail, the current
            // password), or no answer at all, is said rather than swallowed.
            const shownHere = ['name', 'lastname', 'avatar'];
            const elsewhere = Object.keys(errors.value).find((key) => !shownHere.includes(key));
            if (elsewhere || !Object.keys(errors.value).length) {
                error.value = elsewhere ? String(errors.value[elsewhere]) : 'Could not save. Try again.';
            }
            busy.value = false;

            return;
        }

        busy.value = false;
    }

    if (last.value) {
        leave('done');
    } else {
        step.value += 1;
    }
}

function back() {
    if (step.value > 0) step.value -= 1;
}

async function leave(action) {
    // A second press (Escape after "Skip") sends nothing more.
    if (busy.value) return;

    busy.value = true;
    error.value = '';

    try {
        await setupWizardCall(action);
        closed.value = true;
        router.reload({ only: ['setup_wizard'] });
    } catch (status) {
        error.value = status === 419
            ? 'Your session has expired. Reload the page.'
            : 'Could not save. Try again.';
        busy.value = false;
    }
}
</script>

<template>
    <Modal v-if="shown.length && !closed" :open="true" :title="$t('Setting up')" :close-on-backdrop="false" @close="leave('later')">
        <div class="flex flex-col gap-5" data-setup-wizard>
            <p class="text-xs text-ink-soft">
                {{ $t('Step {n} of {total}', { n: step + 1, total: shown.length }) }} · {{ $t(title) }}
            </p>

            <div v-if="current === 'theme'" class="flex flex-wrap gap-2">
                <button
                    v-for="item in THEMES"
                    :key="item.value"
                    type="button"
                    class="rounded-sm border px-4 py-2 text-sm transition"
                    :class="theme === item.value ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-border text-ink hover:bg-surface-subtle'"
                    :data-theme-option="item.value"
                    @click="pickTheme(item.value)"
                >{{ $t(item.label) }}</button>
            </div>

            <div v-else-if="current === 'locale'" class="flex flex-wrap gap-2">
                <button
                    v-for="item in locales"
                    :key="item.code"
                    type="button"
                    class="rounded-sm border px-4 py-2 text-sm transition"
                    :class="page.props.locale === item.code ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-border text-ink hover:bg-surface-subtle'"
                    :data-locale-option="item.code"
                    @click="pickLocale(item.code)"
                >
                    <span v-if="item.flag">{{ item.flag }}</span>
                    {{ item.native }}
                </button>
            </div>

            <div v-else-if="current === 'about'" class="flex flex-col gap-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <InputField v-model="name" name="name" :title="$t('Name')" :errors="errors" />
                    <InputField v-model="lastname" name="lastname" :title="$t('Last name')" :errors="errors" />
                </div>
                <div class="flex items-center gap-4">
                    <UserAvatar :src="state.avatar_url" :name="name" :size="56" />
                    <ImageUpload
                        v-model="avatar"
                        :label="$t('Choose a photo')"
                        :error="errors.avatar ?? ''"
                        :current-url="state.avatar_url ?? ''"
                    />
                </div>
            </div>

            <div v-else-if="current === 'date'" class="flex flex-col gap-2">
                <label
                    v-for="format in DATE_FORMATS"
                    :key="format"
                    class="flex items-center gap-2 text-sm text-ink"
                >
                    <input
                        type="radio"
                        name="setup-date-format"
                        :value="format"
                        :checked="dateFormat === format"
                        @change="pickDate(format)"
                    >
                    {{ format === 'auto' ? $t('Automatic') : formatDate(sample, format, page.props.locale ?? 'en') }}
                </label>
            </div>

            <component :is="setupSteps[current].component" v-else-if="setupSteps[current]" :state="state" />

            <p v-if="error" class="text-sm text-danger" role="alert">{{ $t(error) }}</p>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
                <div class="flex gap-3 text-sm">
                    <button type="button" class="text-ink-soft hover:text-ink" :disabled="busy" @click="leave('later')">{{ $t('Skip') }}</button>
                    <button type="button" class="text-ink-soft hover:text-ink" :disabled="busy" @click="leave('done')">{{ $t('Don\'t show again') }}</button>
                </div>
                <div class="flex gap-2">
                    <button
                        v-if="step > 0"
                        type="button"
                        class="rounded-sm border border-border px-4 py-2 text-sm text-ink transition hover:bg-surface-subtle"
                        :disabled="busy"
                        @click="back"
                    >{{ $t('Back') }}</button>
                    <button
                        type="button"
                        class="rounded-sm bg-brand-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-800 disabled:opacity-50"
                        :disabled="busy"
                        data-setup-next
                        @click="next"
                    >{{ last ? $t('Done') : $t('Next') }}</button>
                </div>
            </div>
        </div>
    </Modal>
</template>
