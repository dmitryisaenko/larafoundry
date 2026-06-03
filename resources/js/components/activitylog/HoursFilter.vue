<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Time-window selector for the activity-log views (phase 2.1).
 *
 * Renders one link per allowed window (1h / 6h / … / 72h). Selecting a window
 * issues a GET with `?hours=N` (Inertia partial reload), preserving the rest of
 * the query string. The server re-validates the value against its allow-list,
 * so a tampered link cannot widen the window beyond what is offered.
 */
const props = defineProps({
    hours: { type: Array, default: () => [] },
    selected: { type: Number, default: 24 },
    baseUrl: { type: String, default: '' },
});

function hrefFor(value) {
    const url = new URL(props.baseUrl || (typeof window !== 'undefined' ? window.location.href : 'http://localhost'), 'http://localhost');
    url.searchParams.set('hours', String(value));
    url.searchParams.delete('page');
    return `${url.pathname}?${url.searchParams.toString()}`;
}

const options = computed(() => props.hours);
</script>

<template>
    <div class="flex flex-wrap items-center gap-2" role="group" :aria-label="$t('Time window')">
        <Link
            v-for="value in options"
            :key="value"
            :href="hrefFor(value)"
            preserve-scroll
            :class="[
                'inline-flex h-8 items-center rounded-sm border px-3 text-sm transition',
                value === selected
                    ? 'border-brand-500 bg-brand-500 text-white'
                    : 'border-border text-ink-soft hover:border-brand-400 hover:text-brand-600',
            ]"
        >
            {{ value }}{{ $t('h') }}
        </Link>
    </div>
</template>
