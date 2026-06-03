<script setup>
import { computed } from 'vue';
import { AdminLayout, PagePaginator } from '@dmitryisaenko/larafoundry';
import ActivityLogTable from '../../../components/activitylog/ActivityLogTable.vue';
import HoursFilter from '../../../components/activitylog/HoursFilter.vue';

/**
 * The whole-platform activity log (phase 2.1) — the operator console's first
 * screen. Super-admin only (gated server-side by `larafoundry.admin`).
 */
const props = defineProps({
    logs: { type: Object, default: () => ({ data: [] }) },
    selectedHours: { type: Number, default: 24 },
    availableHours: { type: Array, default: () => [] },
});

const rows = computed(() => props.logs.data ?? []);
</script>

<template>
    <AdminLayout :title="$t('Activity log')">
        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-ink-soft">{{ $t('Platform-wide activity') }}</p>
                <HoursFilter
                    :hours="availableHours"
                    :selected="selectedHours"
                    base-url="/admin/activity-log"
                />
            </div>

            <div class="rounded-sm border border-border bg-surface p-3">
                <ActivityLogTable :logs="rows" />
            </div>

            <PagePaginator />
        </div>
    </AdminLayout>
</template>
