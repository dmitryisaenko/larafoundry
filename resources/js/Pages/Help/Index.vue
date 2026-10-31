<script setup>
/**
 * The public help page (v0.48.0): one tab per section, one accordion per article.
 *
 * Open to guests. The bodies are rendered with `v-html`, which is safe here
 * BECAUSE the server cleaned them with the HtmlSanitizer on write (the editor and
 * the guide loader are the only writers) and the pictures are built server-side
 * with every value escaped.
 *
 * An article may carry a second text written for a phone (`body_mobile`). The
 * choice is made by CSS at the `md` breakpoint, not by measuring the window in
 * script: the page is right on its first paint and on a rotated tablet alike.
 * Where there is no phone text the desktop one is shown everywhere.
 *
 * A text in another language than the reader's says so (`body_locale`): a
 * substitute shown silently reads as a mistake.
 */
import { computed, ref } from 'vue';
import { AppBaseLayout } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    pages: { type: Object, required: true },
    open: { type: String, default: '' },
});

const keys = computed(() => Object.keys(props.pages));

const active = ref(keys.value.includes(props.open) ? props.open : keys.value[0]);

const page = computed(() => props.pages[active.value] ?? { title: '', blurb: '', items: [] });

/** The open accordion, by index within the active tab. Several may be open. */
const expanded = ref(new Set());

function choose(key) {
    active.value = key;
    expanded.value = new Set();

    // The address follows the tab, so a link copied from here opens the same one.
    try {
        const url = new URL(window.location.href);
        url.searchParams.set('section', key);
        window.history.replaceState(window.history.state, '', url);
    } catch (e) {
        // A sandboxed frame may refuse history writes; the tab still switches.
    }
}

function toggle(at) {
    const next = new Set(expanded.value);

    next.has(at) ? next.delete(at) : next.add(at);
    expanded.value = next;
}
</script>

<template>
    <AppBaseLayout>
        <div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
            <h1 class="text-2xl font-semibold text-ink">{{ $t('Help') }}</h1>

            <nav v-if="keys.length > 1" class="flex flex-wrap gap-1 border-b border-border" :aria-label="$t('Help sections')">
                <button
                    v-for="key in keys"
                    :key="key"
                    type="button"
                    class="-mb-px border-b-2 px-3 py-2 text-sm transition"
                    :class="key === active ? 'border-brand-600 text-brand-700' : 'border-transparent text-ink-soft hover:text-ink'"
                    :aria-current="key === active ? 'page' : undefined"
                    @click="choose(key)"
                >{{ pages[key].title || key }}</button>
            </nav>

            <section class="flex flex-col gap-3">
                <div class="flex flex-col gap-1">
                    <h2 class="text-lg font-semibold text-ink">{{ page.title }}</h2>
                    <p v-if="page.blurb" class="text-sm text-ink-soft">{{ page.blurb }}</p>
                </div>

                <p v-if="! page.items.length" class="text-sm text-ink-soft">{{ $t('Nothing here yet.') }}</p>

                <div
                    v-for="(item, at) in page.items"
                    :key="at"
                    class="rounded-md border border-border bg-surface"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left text-sm font-medium text-ink"
                        :aria-expanded="expanded.has(at)"
                        @click="toggle(at)"
                    >
                        <span>{{ item.heading }}</span>
                        <span class="text-ink-soft" aria-hidden="true">{{ expanded.has(at) ? '−' : '+' }}</span>
                    </button>

                    <div v-if="expanded.has(at)" class="border-t border-border px-4 py-3">
                        <!-- eslint-disable vue/no-v-html -- server-sanitized via HtmlSanitizer -->
                        <div :class="item.body_mobile ? 'hidden md:block' : ''">
                            <p v-if="item.body_locale" class="help-note">{{ $t('There is no translation into your language yet, so the text is shown in the original.') }}</p>
                            <div class="help-body" v-html="item.body" />
                        </div>
                        <div v-if="item.body_mobile" class="md:hidden">
                            <p v-if="item.body_mobile_locale" class="help-note">{{ $t('There is no translation into your language yet, so the text is shown in the original.') }}</p>
                            <div class="help-body" v-html="item.body_mobile" />
                        </div>
                        <!-- eslint-enable vue/no-v-html -->
                    </div>
                </div>
            </section>
        </div>
    </AppBaseLayout>
</template>

<style scoped>
.help-note {
    margin: 0 0 10px;
    font-size: 0.8125rem;
    color: var(--color-ink-soft);
}

/* The body is HTML from the editor: give the tags it can hold a readable shape
   (the reset strips lists and headings bare). `:deep` - v-html is outside scope. */
.help-body {
    color: var(--color-ink);
    font-size: 0.9375rem;
    line-height: 1.6;
}
.help-body :deep(p) { margin: 0 0 10px; }
.help-body :deep(h1),
.help-body :deep(h2),
.help-body :deep(h3),
.help-body :deep(h4),
.help-body :deep(h5),
.help-body :deep(h6) { margin: 16px 0 8px; font-weight: 600; }
.help-body :deep(h1) { font-size: 1.375rem; }
.help-body :deep(h2) { font-size: 1.1875rem; }
.help-body :deep(h3) { font-size: 1.0625rem; }
.help-body :deep(ul),
.help-body :deep(ol) { margin: 0 0 10px; padding-left: 22px; }
.help-body :deep(ul) { list-style: disc; }
.help-body :deep(ol) { list-style: decimal; }
.help-body :deep(blockquote) {
    margin: 0 0 10px;
    padding-left: 12px;
    border-left: 2px solid var(--color-border);
    color: var(--color-ink-soft);
}
.help-body :deep(a) { text-decoration: underline; }

/* Pictures: a desktop ceiling set inline by the server, full width below it. */
.help-body :deep(figure.help-pic) { width: 100%; margin: 14px auto; }
.help-body :deep(figure.help-pic.is-left) { margin-left: 0; }
.help-body :deep(figure.help-pic.is-right) { margin-right: 0; }
.help-body :deep(figure.help-pic img) { display: block; width: 100%; height: auto; border-radius: 6px; }
.help-body :deep(figure.help-pic figcaption) {
    margin-top: 6px;
    font-size: 0.8125rem;
    text-align: center;
    color: var(--color-ink-soft);
}
/* A phone always gets the whole width: the ceiling is a desktop one, and inline it
   would otherwise win (`width=small` stayed 300px on a 390px screen). */
@media (max-width: 767px) {
    .help-body :deep(figure.help-pic) { max-width: 100% !important; }
}
</style>
