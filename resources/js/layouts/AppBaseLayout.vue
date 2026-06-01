<script setup>
import AppFlashMessage from '../components/AppFlashMessage.vue';

/**
 * Minimal de-branded application layout.
 *
 * The honest Ф0.4 baseline: a themed page wrapper with flash toasts wired in,
 * nothing more. Header / footer / navigation are intentionally absent — they
 * couple to navigation (Ф2.3) and tenancy (Ф1.2/1.3) and will arrive with the
 * full role-aware LayoutSwitcher. Until then host pages get a working,
 * branded-by-the-host shell from day one.
 *
 * The `header` and `footer` slots let a host drop in its own chrome without
 * waiting for the navigation module; the default slot is the page body.
 */
</script>

<template>
    <div class="flex min-h-screen flex-col bg-surface-muted text-ink">
        <AppFlashMessage />

        <header v-if="$slots.header" class="border-b border-border bg-surface">
            <div class="mx-auto w-full max-w-[var(--lf-max-width)] px-4 py-3">
                <slot name="header" />
            </div>
        </header>

        <main class="mx-auto w-full max-w-[var(--lf-max-width)] flex-1 px-4 py-6">
            <slot />
        </main>

        <footer v-if="$slots.footer" class="border-t border-border bg-surface">
            <div class="mx-auto w-full max-w-[var(--lf-max-width)] px-4 py-3">
                <slot name="footer" />
            </div>
        </footer>
    </div>
</template>
