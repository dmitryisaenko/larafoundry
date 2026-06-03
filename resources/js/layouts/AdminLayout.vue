<script setup>
import AppFlashMessage from '../components/AppFlashMessage.vue';

/**
 * Minimal super-admin (operator console) shell — the first admin surface
 * (phase 2.1).
 *
 * Deliberately bare: a header band with a "Super-admin" marker and the page
 * body. The full operator navigation (Users / Companies / Payments /
 * Statistics …) arrives with its own phases and will grow this layout; until
 * then it only frames the activity log. The `title` prop labels the current
 * section; the `nav` slot lets later phases inject console links.
 */
defineProps({
    title: { type: String, default: '' },
});
</script>

<template>
    <div class="flex min-h-screen flex-col bg-surface-muted text-ink">
        <AppFlashMessage />

        <header class="border-b border-border bg-surface">
            <div class="mx-auto flex w-full max-w-[var(--lf-max-width)] items-center justify-between px-4 py-3">
                <div class="flex items-center gap-3">
                    <span class="rounded-sm bg-brand-700 px-2 py-1 text-xs font-semibold uppercase tracking-wide text-white">
                        {{ $t('Super-admin') }}
                    </span>
                    <h1 v-if="title" class="text-lg font-semibold text-ink">{{ title }}</h1>
                </div>
                <nav class="flex items-center gap-4 text-sm text-ink-soft">
                    <slot name="nav" />
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-[var(--lf-max-width)] flex-1 px-4 py-6">
            <slot />
        </main>
    </div>
</template>
