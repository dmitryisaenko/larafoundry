<script setup>
/**
 * Personal-data export (phase 5.3) — GDPR data portability.
 *
 * A plain GET link, not an Inertia visit: the endpoint streams a JSON file as an
 * attachment, so the browser must perform a normal navigation to receive the
 * download. It lives in the danger-zone slot the seam left for it, separated by a
 * rule from the deletion control above.
 */
</script>

<template>
    <section class="flex max-w-xl flex-col gap-4 border-t border-border pt-6">
        <header>
            <h2 class="text-base font-semibold text-ink">{{ $t('Export your data') }}</h2>
            <p class="text-sm text-ink-soft">
                {{ $t('Download a copy of your personal data as a JSON file.') }}
            </p>
        </header>

        <a
            href="/profile/data/export"
            class="inline-flex w-fit items-center rounded-sm border border-border px-4 py-2 text-sm font-medium text-ink transition hover:bg-surface-soft"
        >
            {{ $t('Download my data') }}
        </a>
    </section>
</template>
