<script setup>
/**
 * Shared shell for the auth pages.
 *
 * Wraps the centred card, the title/subtitle header and the optional status
 * flash that every Pages/Auth/* screen repeated verbatim. Page bodies go in the
 * default slot; the `footer` slot holds secondary links (register / back to
 * login). Keeping this in one place means a restyle of the auth surface is a
 * single edit instead of nine.
 */
defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    status: { type: String, default: null },
});
</script>

<template>
    <div class="mx-auto w-full max-w-md rounded-md border border-border bg-surface p-6 shadow-sm">
        <h1 v-if="title" class="mb-1 text-xl font-semibold text-ink">{{ title }}</h1>
        <p v-if="subtitle" class="mb-6 text-sm text-ink-soft">{{ subtitle }}</p>

        <p v-if="status" class="mb-4 rounded-sm bg-brand-50 px-3 py-2 text-sm text-brand-700">
            {{ status }}
        </p>

        <slot />

        <div v-if="$slots.footer" class="mt-6 text-center text-sm text-ink-soft">
            <slot name="footer" />
        </div>
    </div>
</template>
