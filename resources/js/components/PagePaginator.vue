<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Page-number paginator driven by the Inertia `pagination` prop.
 *
 * Consumes the payload shaped by the backend `HasPagination` trait
 * (`current_page` / `last_page` / `per_page` / `total` / `from` / `to`).
 * Preserves the current query string across page links. Renders a compact
 * range with ellipses for long result sets. Tailwind-styled.
 *
 * Legacy-parity layout (kohana): the row reads `from … to` on the left, the
 * numbered page links in the centre, and the grand `total` on the right. It is
 * hidden entirely for a single page of results — a lone "1" is noise.
 */
const page = usePage();

const p = computed(() => page.props.pagination ?? {
    total: 0,
    current_page: 1,
    last_page: 1,
    per_page: 25,
    from: 0,
    to: 0,
});

const queryString = computed(() => {
    if (typeof window === 'undefined') {
        return '';
    }
    const url = new URL(window.location.href);
    url.searchParams.delete('page');
    const qs = url.searchParams.toString();
    return qs ? `&${qs}` : '';
});

const pages = computed(() => {
    const total = p.value.last_page;
    const current = p.value.current_page;

    if (total <= 7) {
        return Array.from({ length: total }, (_, i) => i + 1);
    }
    if (current <= 4) {
        return [1, 2, 3, 4, 5, '...', total];
    }
    if (current >= total - 3) {
        return [1, '...', total - 4, total - 3, total - 2, total - 1, total];
    }
    return [1, '...', current - 1, current, current + 1, '...', total];
});
</script>

<template>
    <!-- Hidden for a single page: a lone "1" is noise (legacy parity). -->
    <nav v-if="p.last_page > 1" class="flex flex-wrap items-center justify-between gap-3" aria-label="Pagination">
        <p class="min-w-16 text-sm text-ink-soft">{{ p.from }} … {{ p.to }}</p>

        <div class="flex items-center gap-1">
            <template v-for="(item, index) in pages" :key="index">
                <span v-if="item === '...'" class="px-2 text-ink-faint">…</span>

                <span
                    v-else-if="item === p.current_page"
                    class="inline-flex h-9 min-w-9 items-center justify-center rounded-sm bg-brand-500 px-3 text-sm font-medium text-white"
                >
                    {{ item }}
                </span>

                <Link
                    v-else
                    :href="`?page=${item}${queryString}`"
                    class="inline-flex h-9 min-w-9 items-center justify-center rounded-sm border border-border px-3 text-sm text-ink transition hover:border-brand-400 hover:text-brand-600"
                >
                    {{ item }}
                </Link>
            </template>
        </div>

        <p class="min-w-16 text-right text-sm text-ink-soft">{{ p.total }}</p>
    </nav>
</template>
