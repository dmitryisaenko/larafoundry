<script setup>
/**
 * Recent activity widget (phase 3.4) — a 24h count plus a compact feed of the
 * latest super-admin (`admin` log) events. The feed comes pre-shaped from the
 * backend (description, causer, time); this only renders it.
 */
import { computed } from 'vue';
import WidgetCard from './WidgetCard.vue';
import { useDateFormat } from '../../../composables/useDateFormat.js';

const props = defineProps({
    titleKey: { type: String, default: '' },
    data: { type: Object, default: () => ({}) },
    meta: { type: Object, default: () => ({}) },
});

const recent = computed(() => props.data.recent ?? []);

// Honours the user's date_format preference (auto/dmy/mdy/iso) + app locale.
const { formatDateTime } = useDateFormat();
</script>

<template>
    <WidgetCard :title="$t(titleKey)" :icon="meta.icon">
        <div class="mb-3 flex items-baseline gap-2">
            <span class="text-2xl font-semibold text-ink">{{ data.last_24h ?? 0 }}</span>
            <span class="text-xs text-ink-soft">{{ $t('Last 24 hours') }}</span>
        </div>

        <ul v-if="recent.length" class="divide-y divide-border text-sm">
            <li v-for="entry in recent" :key="entry.id" class="flex items-center justify-between gap-3 py-2">
                <span class="min-w-0 flex-1 truncate text-ink">{{ entry.description }}</span>
                <span class="shrink-0 text-xs text-ink-soft">{{ entry.causer || $t('Guest') }}</span>
                <span class="shrink-0 text-xs text-ink-faint">{{ formatDateTime(entry.created_at) }}</span>
            </li>
        </ul>
        <p v-else class="text-sm text-ink-soft">{{ $t('No recent activity') }}</p>
    </WidgetCard>
</template>
