<script setup>
/**
 * Header entry point to the user's support tickets (phase 4.2, decision D-4.2-6).
 *
 * A small icon-link that the core's layouts render next to the notification bell
 * — the same out-of-the-box placement pattern as the bell, mirroring the donor's
 * header "Support" icon. It is a plain Inertia GET <Link> to the user's own
 * ticket inbox; every authenticated user (including a blocked one) can reach it.
 */
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <Link
        href="/tickets"
        class="relative inline-flex h-9 w-9 items-center justify-center rounded-sm text-ink-soft transition hover:bg-surface-accent hover:text-ink"
        :aria-label="$t('Support')"
        :title="$t('Support')"
    >
        <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            class="h-5 w-5"
            aria-hidden="true"
        >
            <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z"
            />
        </svg>
    </Link>
</template>
