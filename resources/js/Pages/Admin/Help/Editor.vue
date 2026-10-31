<script setup>
/**
 * The help editor (v0.48.0), super-admin only.
 *
 * The page loads once and then talks JSON to `/admin/help-articles/*`: an editor
 * that reloaded on every save would throw away what is typed in other articles.
 *
 * The SOURCE language (`source`) is where text is written; the others are
 * translations of it, so their tabs carry a "translate" button - shown only when
 * the host bound a translator (`can_translate`). A translation is put into the
 * field and waits for "Save": a model that misread something must not be able to
 * publish it.
 *
 * The language is chosen PER ARTICLE (comparing two languages of one article must
 * not move every other field), and an article has two texts - for a computer and,
 * optionally, for a phone. The last view and language are remembered in this
 * browser only.
 */
import { computed, ref, watch } from 'vue';
import { AdminLayout, confirm, useT } from '@dmitryisaenko/larafoundry';
import HelpRichText from '../../../components/help/HelpRichText.vue';

const props = defineProps({
    sections: { type: Array, default: () => [] },
    pictures: { type: Array, default: () => [] },
    locales: { type: Array, default: () => ['en'] },
    source: { type: String, default: 'en' },
    can_translate: { type: Boolean, default: false },
});

const t = useT();

const BASE = '/admin/help-articles';
const REMEMBERED_VIEW = 'larafoundry.help.view';
const REMEMBERED_LANG = 'larafoundry.help.lang';

const VIEWS = ['overview', 'help', 'pictures'];
const DEVICES = [
    { key: 'body', label: 'Computer' },
    { key: 'body_mobile', label: 'Phone' },
];

function remembered(key, fallback) {
    try {
        return window.localStorage.getItem(key) || fallback;
    } catch (e) {
        return fallback;
    }
}

function remember(key, value) {
    try {
        window.localStorage.setItem(key, value);
    } catch (e) {
        // A private window refuses storage; losing the memory of a tab costs nothing.
    }
}

/** A language's own name for itself ("Українська"), the code when the browser has none. */
function nameOf(code) {
    try {
        const name = new Intl.DisplayNames([code], { type: 'language' }).of(code);

        return name ? name.charAt(0).toUpperCase() + name.slice(1) : code;
    } catch (e) {
        return code;
    }
}

const rows = ref(props.sections.map((s) => ({ ...s })));
const pics = ref(props.pictures.map((p) => ({ ...p })));

const view = ref(VIEWS.includes(remembered(REMEMBERED_VIEW, '')) ? remembered(REMEMBERED_VIEW, 'overview') : 'overview');
watch(view, (now) => remember(REMEMBERED_VIEW, now));

const lastLang = ref(props.locales.includes(remembered(REMEMBERED_LANG, '')) ? remembered(REMEMBERED_LANG, props.source) : props.source);
const articleLang = ref({});
const articleDevice = ref({});
const sectionLang = ref(props.source);
const picLang = ref({});

const openSection = ref(props.sections[0]?.id ?? null);
const openArticle = ref(null);
const onlyGaps = ref(false);
const busy = ref(false);
const problem = ref('');
const said = ref('');

const section = computed(() => rows.value.find((s) => s.id === openSection.value) ?? null);
const article = computed(() => section.value?.articles.find((a) => a.id === openArticle.value) ?? null);
const targets = computed(() => props.locales.filter((c) => c !== props.source));

function langOf(id) {
    return articleLang.value[id] ?? lastLang.value;
}

function setLang(id, code) {
    articleLang.value = { ...articleLang.value, [id]: code };
    lastLang.value = code;
    remember(REMEMBERED_LANG, code);
}

function deviceOf(id) {
    return articleDevice.value[id] ?? 'body';
}

function setDevice(id, key) {
    articleDevice.value = { ...articleDevice.value, [id]: key };
}

/** The text being edited, created on first use (an article may have no phone text yet). */
function bodyOf(a) {
    const key = deviceOf(a.id);

    if (! a[key] || typeof a[key] !== 'object' || Array.isArray(a[key])) a[key] = {};

    return a[key];
}

function picLangOf(id) {
    return picLang.value[id] ?? lastLang.value;
}

function written(html) {
    const text = String(html ?? '');

    return text.includes('[[img:') || text.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim() !== '';
}

function has(field, code) {
    return typeof field?.[code] === 'string' && field[code].trim() !== '';
}

/** Translated = BOTH the heading and the text are there; half is what this table finds. */
function articleDone(a, code) {
    return has(a.heading, code) && written(a.body?.[code]);
}

function sectionDone(s, code) {
    return has(s.title, code) && s.articles.every((a) => articleDone(a, code));
}

const coverage = computed(() => {
    const list = rows.value.map((s) => ({
        id: s.id,
        label: s.title[props.source] || s.key,
        count: s.articles.length,
        done: (code) => sectionDone(s, code),
        doneCount: (code) => s.articles.filter((a) => articleDone(a, code)).length,
        children: s.articles.map((a) => ({
            id: a.id,
            label: a.heading[props.source] || t('(no heading)'),
            done: (code) => articleDone(a, code),
        })),
    }));

    if (! onlyGaps.value) return list;

    // A section stays when it OR any article in it is incomplete.
    return list
        .map((row) => ({ ...row, children: row.children.filter((c) => targets.value.some((code) => ! c.done(code))) }))
        .filter((row) => row.children.length || targets.value.some((code) => ! row.done(code)));
});

function say(text) {
    said.value = text;
    window.setTimeout(() => { said.value = ''; }, 2500);
}

function xsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** One call, its failure shown, `busy` always cleared - a screen left disabled reads as "still saving". */
async function call(url, method = 'POST', body = null) {
    busy.value = true;
    problem.value = '';

    try {
        const isForm = body instanceof FormData;
        const res = await fetch(url, {
            method,
            headers: {
                Accept: 'application/json',
                ...(isForm || body === null ? {} : { 'Content-Type': 'application/json' }),
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: body === null ? null : (isForm ? body : JSON.stringify(body)),
        });

        const data = await res.json().catch(() => ({}));

        if (! res.ok) throw new Error(data.message || `HTTP ${res.status}`);

        return data;
    } catch (e) {
        problem.value = e.message;

        return null;
    } finally {
        busy.value = false;
    }
}

// ------------------------------------------------------------------ sections

async function addSection() {
    const key = window.prompt(t('Section key, latin letters (it goes into the address):'), '');

    if (! key) return;

    const title = window.prompt(t('Section title in {lang}:', { lang: nameOf(props.source) }), '');

    if (! title) return;

    const data = await call(`${BASE}/sections`, 'POST', { key, title: { [props.source]: title }, blurb: {} });

    if (data) {
        rows.value.push(data.section);
        openSection.value = data.section.id;
        say(t('Section created'));
    }
}

async function saveSection() {
    if (! section.value) return;

    const data = await call(`${BASE}/sections/${section.value.id}`, 'PATCH', {
        key: section.value.key,
        title: section.value.title,
        blurb: section.value.blurb,
        is_published: section.value.is_published,
    });

    if (data) say(t('Section saved'));
}

async function removeSection() {
    if (! section.value) return;

    const ok = await confirm({
        variant: 'danger',
        title: t('Delete the section'),
        message: t('The section and every article in it ({n}) will be deleted. This cannot be undone.', { n: section.value.articles.length }),
        highlight: section.value.title[props.source] || section.value.key,
        confirmLabel: t('Delete'),
    });

    if (! ok) return;

    const id = section.value.id;
    const data = await call(`${BASE}/sections/${id}`, 'DELETE');

    if (data) {
        rows.value = rows.value.filter((s) => s.id !== id);
        openSection.value = rows.value[0]?.id ?? null;
        say(t('Section deleted'));
    }
}

/** The whole order is sent, never "this one moved". */
async function moveSection(at, by) {
    const to = at + by;

    if (to < 0 || to >= rows.value.length) return;

    const list = [...rows.value];
    [list[at], list[to]] = [list[to], list[at]];
    rows.value = list;

    await call(`${BASE}/sections/order`, 'POST', { ids: list.map((s) => s.id) });
}

async function translateSectionTitle() {
    if (! section.value || sectionLang.value === props.source) return;

    const from = section.value.title[props.source] ?? '';

    if (! from.trim()) return;

    const data = await call(`${BASE}/translate`, 'POST', { text: from, to: sectionLang.value });

    if (data) {
        section.value.title = { ...section.value.title, [sectionLang.value]: data.text };
        say(t('Translation put in. Check it and save.'));
    }
}

// ------------------------------------------------------------------ articles

async function addArticle() {
    if (! section.value) return;

    const heading = window.prompt(t('Article heading in {lang}:', { lang: nameOf(props.source) }), '');

    if (! heading) return;

    const data = await call(`${BASE}/sections/${section.value.id}/articles`, 'POST', {
        heading: { [props.source]: heading },
        body: { [props.source]: '<p></p>' },
    });

    if (data) {
        section.value.articles.push(data.article);
        openArticle.value = data.article.id;
        say(t('Article created'));
    }
}

async function saveArticle() {
    if (! article.value) return;

    const data = await call(`${BASE}/articles/${article.value.id}`, 'PATCH', {
        heading: article.value.heading,
        body: article.value.body,
        body_mobile: article.value.body_mobile ?? {},
        is_published: article.value.is_published,
    });

    if (data) say(t('Article saved'));
}

async function removeArticle() {
    if (! article.value) return;

    const ok = await confirm({
        variant: 'danger',
        title: t('Delete the article'),
        message: t('This cannot be undone.'),
        highlight: article.value.heading[props.source] || '',
        confirmLabel: t('Delete'),
    });

    if (! ok) return;

    const id = article.value.id;
    const data = await call(`${BASE}/articles/${id}`, 'DELETE');

    if (data) {
        section.value.articles = section.value.articles.filter((a) => a.id !== id);
        openArticle.value = null;
        say(t('Article deleted'));
    }
}

async function moveArticle(at, by) {
    if (! section.value) return;

    const to = at + by;
    const list = [...section.value.articles];

    if (to < 0 || to >= list.length) return;

    [list[at], list[to]] = [list[to], list[at]];
    section.value.articles = list;

    await call(`${BASE}/sections/${section.value.id}/articles/order`, 'POST', { ids: list.map((a) => a.id) });
}

async function translateField(a, which) {
    const to = langOf(a.id);
    const from = a[which]?.[props.source] ?? '';

    if (! String(from).trim()) {
        problem.value = t('This field is empty in {lang} - there is nothing to translate.', { lang: nameOf(props.source) });

        return false;
    }

    const data = await call(`${BASE}/translate`, 'POST', { text: from, to });

    if (! data) return false;

    if (! a[which] || typeof a[which] !== 'object' || Array.isArray(a[which])) a[which] = {};
    a[which][to] = data.text;

    return true;
}

/** Every field of one article in one press; the phone text only when the source has one. */
async function translateArticle(a) {
    if (langOf(a.id) === props.source) return;

    const ok = await translateField(a, 'heading')
        && await translateField(a, 'body')
        && (! written(a.body_mobile?.[props.source]) || await translateField(a, 'body_mobile'));

    if (ok) say(t('Translation put in. Check it and save.'));
}

// ------------------------------------------------------------------ pictures

const picInput = ref(null);

async function reloadPictures() {
    const data = await call(`${BASE}/pictures`, 'GET');

    if (data?.pictures) pics.value = data.pictures;
}

async function uploadPicture(event) {
    const file = event.target.files?.[0];

    event.target.value = '';

    if (! file) return;

    const body = new FormData();
    body.append('image', file);
    body.append('ref', file.name.replace(/\.[^.]+$/, ''));

    const data = await call(`${BASE}/pictures`, 'POST', body);

    if (data) {
        // Re-read the pictures only - never the texts, which may hold unsaved edits.
        await reloadPictures();
        say(t('Picture uploaded'));
    }
}

async function savePicture(pic) {
    const data = await call(`${BASE}/pictures/${pic.id}`, 'PATCH', {
        ref: pic.ref,
        caption: pic.caption,
        max_width: pic.max_width || null,
    });

    if (data) {
        Object.assign(pic, data.picture);
        say(t('Picture saved'));
    }
}

async function removePicture(pic) {
    const ok = await confirm({
        variant: 'danger',
        title: t('Delete the picture'),
        message: t('The file is erased, and wherever it was referred to nothing will show.'),
        highlight: pic.ref,
        confirmLabel: t('Delete'),
    });

    if (! ok) return;

    const data = await call(`${BASE}/pictures/${pic.id}`, 'DELETE');

    if (data) {
        pics.value = pics.value.filter((p) => p.id !== pic.id);
        say(t('Picture deleted'));
    }
}

async function copyMarker(pic) {
    try {
        await navigator.clipboard.writeText(pic.marker);
        say(t('Reference copied - paste it into a text'));
    } catch (e) {
        problem.value = t('Could not copy. Copy it by hand: {marker}', { marker: pic.marker });
    }
}
</script>

<template>
    <AdminLayout :title="$t('Help')">
        <div class="flex flex-col gap-4">
            <p class="text-sm text-ink-soft">
                {{ $t('Sections and articles of the help. Text is written in {lang} and translated from it.', { lang: nameOf(source) }) }}
            </p>

            <div v-if="problem" class="rounded-md border border-danger-200 bg-danger-50 px-3 py-2 text-sm text-danger-700" role="alert">{{ problem }}</div>
            <div v-if="said" class="rounded-md border border-border bg-surface-subtle px-3 py-2 text-sm text-success" role="status">{{ said }}</div>

            <div class="flex flex-wrap items-center gap-2 border-b border-border">
                <button
                    v-for="key in VIEWS"
                    :key="key"
                    type="button"
                    class="-mb-px border-b-2 px-3 py-2 text-sm transition"
                    :class="view === key ? 'border-brand-600 text-brand-700' : 'border-transparent text-ink-soft hover:text-ink'"
                    @click="view = key"
                >{{ $t({ overview: 'Overview', help: 'Sections', pictures: 'Pictures' }[key]) }}</button>
            </div>

            <!-- ============================================ overview -->
            <div v-if="view === 'overview'" class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-medium text-ink">{{ $t('Translations') }}</h2>
                    <button
                        type="button"
                        class="rounded border px-3 py-1 text-xs transition"
                        :class="onlyGaps ? 'border-brand-600 text-brand-700' : 'border-border text-ink-soft hover:text-ink'"
                        @click="onlyGaps = ! onlyGaps"
                    >{{ onlyGaps ? $t('Showing only what is missing') : $t('Show what is not translated') }}</button>
                </div>

                <p v-if="! targets.length" class="text-sm text-ink-soft">{{ $t('The app speaks one language, so there is nothing to translate.') }}</p>

                <div v-else class="overflow-x-auto rounded-md border border-border">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border text-ink-soft">
                                <th class="px-3 py-2 text-left font-medium">{{ $t('Text') }}</th>
                                <th v-for="code in targets" :key="code" class="w-28 px-3 py-2 text-center font-medium">{{ nameOf(code) }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="row in coverage" :key="row.id">
                                <tr class="border-b border-border bg-surface-subtle">
                                    <td class="px-3 py-2 font-medium text-ink">{{ row.label }} <span class="text-xs text-ink-faint">· {{ row.count }}</span></td>
                                    <td v-for="code in targets" :key="code" class="px-3 py-2 text-center">
                                        <span v-if="row.done(code)" class="text-success-strong">✓</span>
                                        <span v-else class="text-danger">✕ <span class="text-xs text-ink-faint">{{ row.doneCount(code) }}/{{ row.count }}</span></span>
                                    </td>
                                </tr>
                                <tr v-for="child in row.children" :key="`a${child.id}`" class="border-b border-border last:border-b-0">
                                    <td class="px-3 py-1.5 pl-8 text-ink-soft">{{ child.label }}</td>
                                    <td v-for="code in targets" :key="code" class="px-3 py-1.5 text-center">
                                        <span v-if="child.done(code)" class="text-success-strong">✓</span>
                                        <span v-else class="text-danger">✕</span>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="! coverage.length">
                                <td :colspan="targets.length + 1" class="px-3 py-5 text-center text-ink-faint">
                                    {{ onlyGaps ? $t('Everything is translated.') : $t('The help is empty.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ============================================ sections and articles -->
            <div v-show="view === 'help'" class="grid grid-cols-1 gap-4 lg:grid-cols-[260px_1fr]">
                <div class="flex flex-col gap-2">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-ink">{{ $t('Sections') }}</span>
                        <button type="button" class="rounded border border-border px-2 py-1 text-xs text-ink-soft hover:text-ink" :disabled="busy" @click="addSection">+ {{ $t('section') }}</button>
                    </div>

                    <ul class="flex flex-col rounded-md border border-border">
                        <li
                            v-for="(s, at) in rows" :key="s.id"
                            class="flex items-center gap-1 border-b border-border px-2 py-2 last:border-b-0"
                            :class="openSection === s.id ? 'bg-surface-subtle' : ''"
                        >
                            <button
                                type="button" class="min-w-0 flex-1 text-left text-sm"
                                :class="s.is_published ? 'text-ink' : 'text-ink-faint line-through'"
                                @click="openSection = s.id; openArticle = null"
                            >
                                {{ s.title[source] || s.key }}
                                <span class="block text-xs text-ink-faint">{{ s.key }} · {{ s.articles.length }}</span>
                            </button>
                            <div class="flex flex-col">
                                <button type="button" class="px-1 text-xs text-ink-faint hover:text-ink" :aria-label="$t('Move up')" :disabled="busy || at === 0" @click="moveSection(at, -1)">▲</button>
                                <button type="button" class="px-1 text-xs text-ink-faint hover:text-ink" :aria-label="$t('Move down')" :disabled="busy || at === rows.length - 1" @click="moveSection(at, 1)">▼</button>
                            </div>
                        </li>
                        <li v-if="! rows.length" class="px-3 py-4 text-center text-sm text-ink-faint">{{ $t('No sections yet.') }}</li>
                    </ul>
                </div>

                <div v-if="section" class="flex flex-col gap-4">
                    <div class="flex flex-col gap-3 rounded-md border border-border p-4">
                        <label class="flex flex-col gap-1">
                            <span class="text-xs text-ink-soft">{{ $t('Key (the section\'s address)') }}</span>
                            <input v-model="section.key" type="text" class="rounded border border-border bg-transparent px-2 py-1 text-sm text-ink">
                        </label>

                        <div class="flex flex-wrap items-center gap-1 border-b border-border pb-2">
                            <button
                                v-for="code in locales" :key="code"
                                type="button"
                                class="rounded-md px-2.5 py-1 text-xs transition"
                                :class="sectionLang === code ? 'bg-brand-600 text-white' : 'text-ink-soft hover:text-ink'"
                                @click="sectionLang = code"
                            >{{ nameOf(code) }}<template v-if="code === source"> · {{ $t('source') }}</template></button>
                        </div>

                        <label class="flex flex-col gap-1">
                            <span class="flex items-center justify-between text-xs text-ink-soft">
                                <span>{{ $t('Title') }} · {{ nameOf(sectionLang) }}</span>
                                <button
                                    v-if="can_translate && sectionLang !== source" type="button"
                                    class="rounded border border-border px-2 py-0.5 text-xs text-ink-soft hover:text-ink"
                                    :disabled="busy" @click="translateSectionTitle"
                                >{{ $t('Translate') }}</button>
                            </span>
                            <input v-model="section.title[sectionLang]" type="text" class="rounded border border-border bg-transparent px-2 py-1 text-sm text-ink">
                        </label>

                        <label class="flex flex-col gap-1">
                            <span class="text-xs text-ink-soft">{{ $t('Subtitle') }} · {{ nameOf(sectionLang) }}</span>
                            <input v-model="section.blurb[sectionLang]" type="text" class="rounded border border-border bg-transparent px-2 py-1 text-sm text-ink">
                        </label>

                        <label class="flex items-center gap-2 text-sm text-ink-soft">
                            <input v-model="section.is_published" type="checkbox">
                            {{ $t('Show to readers') }}
                        </label>

                        <div class="flex items-center gap-2">
                            <button type="button" class="rounded bg-brand-600 px-3 py-1.5 text-sm text-white hover:bg-brand-700 disabled:opacity-40" :disabled="busy" @click="saveSection">{{ $t('Save the section') }}</button>
                            <button type="button" class="rounded border border-danger-200 px-3 py-1.5 text-sm text-danger-700 hover:bg-danger-50 disabled:opacity-40" :disabled="busy" @click="removeSection">{{ $t('Delete the section') }}</button>
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-ink">{{ $t('Articles') }}</span>
                        <button type="button" class="rounded border border-border px-2 py-1 text-xs text-ink-soft hover:text-ink" :disabled="busy" @click="addArticle">+ {{ $t('article') }}</button>
                    </div>

                    <ul class="flex flex-col rounded-md border border-border">
                        <li v-for="(a, at) in section.articles" :key="a.id" class="border-b border-border last:border-b-0">
                            <div class="flex items-center gap-1 px-2 py-2" :class="openArticle === a.id ? 'bg-surface-subtle' : ''">
                                <button
                                    type="button" class="min-w-0 flex-1 text-left text-sm"
                                    :class="a.is_published ? 'text-ink' : 'text-ink-faint line-through'"
                                    @click="openArticle = openArticle === a.id ? null : a.id"
                                >{{ a.heading[source] || $t('(no heading)') }}</button>
                                <div class="flex flex-col">
                                    <button type="button" class="px-1 text-xs text-ink-faint hover:text-ink" :aria-label="$t('Move up')" :disabled="busy || at === 0" @click="moveArticle(at, -1)">▲</button>
                                    <button type="button" class="px-1 text-xs text-ink-faint hover:text-ink" :aria-label="$t('Move down')" :disabled="busy || at === section.articles.length - 1" @click="moveArticle(at, 1)">▼</button>
                                </div>
                            </div>

                            <div v-if="openArticle === a.id" class="flex flex-col gap-3 border-t border-border px-3 py-3">
                                <div class="flex flex-wrap items-center gap-1 border-b border-border pb-2">
                                    <button
                                        v-for="code in locales" :key="code"
                                        type="button"
                                        class="rounded-md px-2.5 py-1 text-xs transition"
                                        :class="langOf(a.id) === code ? 'bg-brand-600 text-white' : 'text-ink-soft hover:text-ink'"
                                        @click="setLang(a.id, code)"
                                    >
                                        {{ nameOf(code) }}<template v-if="code === source"> · {{ $t('source') }}</template>
                                        <span v-if="code !== source && ! articleDone(a, code)" class="ml-1 text-danger">•</span>
                                    </button>

                                    <button
                                        v-if="can_translate && langOf(a.id) !== source"
                                        type="button"
                                        class="ml-auto rounded border border-border px-2 py-1 text-xs text-ink-soft hover:text-ink disabled:opacity-40"
                                        :disabled="busy"
                                        @click="translateArticle(a)"
                                    >{{ $t('Translate') }}</button>
                                </div>

                                <label class="flex flex-col gap-1">
                                    <span class="text-xs text-ink-soft">{{ $t('Heading') }} · {{ nameOf(langOf(a.id)) }}</span>
                                    <input v-model="a.heading[langOf(a.id)]" type="text" class="rounded border border-border bg-transparent px-2 py-1 text-sm text-ink">
                                </label>

                                <div class="flex flex-col gap-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-xs text-ink-soft">{{ $t('Text') }} · {{ nameOf(langOf(a.id)) }}</span>
                                        <div class="ml-auto flex rounded-md border border-border p-0.5">
                                            <button
                                                v-for="d in DEVICES" :key="d.key"
                                                type="button"
                                                class="rounded px-2 py-0.5 text-xs transition"
                                                :class="deviceOf(a.id) === d.key ? 'bg-brand-600 text-white' : 'text-ink-soft hover:text-ink'"
                                                @click="setDevice(a.id, d.key)"
                                            >{{ $t(d.label) }}<span v-if="d.key === 'body_mobile' && written(a.body_mobile?.[langOf(a.id)])" class="ml-1">•</span></button>
                                        </div>
                                    </div>
                                    <p v-if="deviceOf(a.id) === 'body_mobile'" class="text-xs text-ink-faint">
                                        {{ $t('Optional. While this is empty, a phone shows the computer text.') }}
                                    </p>
                                    <!-- One editor per text, so switching never carries the other's undo history. -->
                                    <HelpRichText
                                        :key="`${a.id}-${deviceOf(a.id)}-${langOf(a.id)}`"
                                        v-model="bodyOf(a)[langOf(a.id)]"
                                        :disabled="busy"
                                        :upload-url="`${BASE}/pictures`"
                                        @uploaded="reloadPictures"
                                    />
                                </div>

                                <label class="flex items-center gap-2 text-sm text-ink-soft">
                                    <input v-model="a.is_published" type="checkbox">
                                    {{ $t('Show to readers') }}
                                </label>

                                <div class="flex items-center gap-2">
                                    <button type="button" class="rounded bg-brand-600 px-3 py-1.5 text-sm text-white hover:bg-brand-700 disabled:opacity-40" :disabled="busy" @click="saveArticle">{{ $t('Save the article') }}</button>
                                    <button type="button" class="rounded border border-danger-200 px-3 py-1.5 text-sm text-danger-700 hover:bg-danger-50 disabled:opacity-40" :disabled="busy" @click="removeArticle">{{ $t('Delete') }}</button>
                                </div>
                            </div>
                        </li>

                        <li v-if="! section.articles.length" class="px-3 py-4 text-center text-sm text-ink-faint">{{ $t('No articles in this section.') }}</li>
                    </ul>
                </div>
            </div>

            <!-- ============================================ pictures -->
            <div v-show="view === 'pictures'" class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-sm font-medium text-ink">{{ $t('Pictures') }}</span>
                    <input ref="picInput" type="file" accept="image/jpeg,image/png,image/webp,image/gif" hidden @change="uploadPicture">
                    <button type="button" class="rounded border border-border px-2 py-1 text-xs text-ink-soft hover:text-ink disabled:opacity-40" :disabled="busy" @click="picInput?.click()">+ {{ $t('picture') }}</button>
                </div>

                <p class="text-xs text-ink-faint">
                    {{ $t('To put a picture into a text, copy its reference and paste it where it belongs. One reference works in every language, so a picture is replaced here, once.') }}
                </p>

                <ul class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <li v-for="pic in pics" :key="pic.id" class="flex flex-col gap-2 rounded-md border border-border p-3">
                        <img :src="pic.url" :alt="pic.ref" class="max-h-40 w-full rounded border border-border object-contain">

                        <label class="flex flex-col gap-1">
                            <span class="text-xs text-ink-soft">{{ $t('Name (texts refer to it by this)') }}</span>
                            <input v-model="pic.ref" type="text" class="rounded border border-border bg-transparent px-2 py-1 text-sm text-ink">
                        </label>

                        <div class="flex items-center gap-2">
                            <code class="min-w-0 flex-1 truncate rounded bg-surface-subtle px-2 py-1 text-xs text-ink-soft">{{ pic.marker }}</code>
                            <button type="button" class="rounded border border-border px-2 py-1 text-xs text-ink-soft hover:text-ink" @click="copyMarker(pic)">{{ $t('Copy') }}</button>
                        </div>

                        <div class="flex flex-wrap items-center gap-1">
                            <button
                                v-for="code in locales" :key="code"
                                type="button"
                                class="rounded-md px-2 py-0.5 text-xs transition"
                                :class="picLangOf(pic.id) === code ? 'bg-brand-600 text-white' : 'text-ink-soft hover:text-ink'"
                                @click="picLang = { ...picLang, [pic.id]: code }"
                            >{{ nameOf(code) }}</button>
                        </div>

                        <label class="flex flex-col gap-1">
                            <span class="text-xs text-ink-soft">{{ $t('Caption') }} · {{ nameOf(picLangOf(pic.id)) }}</span>
                            <input
                                :value="pic.caption[picLangOf(pic.id)] ?? ''"
                                type="text"
                                class="rounded border border-border bg-transparent px-2 py-1 text-sm text-ink"
                                @input="pic.caption = { ...pic.caption, [picLangOf(pic.id)]: $event.target.value }"
                            >
                        </label>

                        <label class="flex flex-col gap-1">
                            <span class="text-xs text-ink-soft">{{ $t('Width on a computer, in pixels - empty means the default') }}</span>
                            <input v-model.number="pic.max_width" type="number" min="200" max="2000" class="w-32 rounded border border-border bg-transparent px-2 py-1 text-sm text-ink">
                        </label>

                        <div class="flex items-center gap-2">
                            <button type="button" class="rounded bg-brand-600 px-3 py-1.5 text-sm text-white hover:bg-brand-700 disabled:opacity-40" :disabled="busy" @click="savePicture(pic)">{{ $t('Save') }}</button>
                            <button type="button" class="rounded border border-danger-200 px-3 py-1.5 text-sm text-danger-700 hover:bg-danger-50 disabled:opacity-40" :disabled="busy" @click="removePicture(pic)">{{ $t('Delete') }}</button>
                        </div>
                    </li>

                    <li v-if="! pics.length" class="rounded-md border border-border px-3 py-6 text-center text-sm text-ink-faint md:col-span-2">
                        {{ $t('No pictures yet. Upload the first one with the button above or from an article\'s editor.') }}
                    </li>
                </ul>
            </div>
        </div>
    </AdminLayout>
</template>
