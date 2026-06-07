<script setup>
/**
 * Public legal page — Terms, Privacy, Cookie policy (phase 5.3).
 *
 * Open to everyone (guests included). The body is rendered with `v-html`, which is
 * safe here BECAUSE the server already ran it through the HtmlSanitizer (an
 * email-and-document-friendly allowlist that drops scripts, event handlers and
 * dangerous URIs) when resolving the page — the same single sanitizer authority
 * used on save. The controller 404s an unpublished or unknown page, so this never
 * renders a placeholder default.
 */
import { AppBaseLayout } from '@dmitryisaenko/larafoundry';

defineProps({
    page: { type: Object, required: true },
});
</script>

<template>
    <AppBaseLayout>
        <article class="mx-auto flex w-full max-w-3xl flex-col gap-6 rounded-md border border-border bg-surface p-6">
            <h1 class="text-2xl font-semibold text-ink">{{ page.title }}</h1>
            <!-- eslint-disable-next-line vue/no-v-html -- server-sanitized via HtmlSanitizer -->
            <div class="legal-body flex flex-col gap-3 text-ink" v-html="page.body_html" />
        </article>
    </AppBaseLayout>
</template>
