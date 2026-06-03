<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { AdminLayout, PagePaginator, ActivityLogTable, HoursFilter } from '@dmitryisaenko/larafoundry';

/**
 * Activity for a single user, scoped to that user as causer (phase 2.1).
 * Super-admin only.
 */
const props = defineProps({
    targetUser: { type: Object, default: () => ({}) },
    logs: { type: Object, default: () => ({ data: [] }) },
    selectedHours: { type: Number, default: 1 },
    availableHours: { type: Array, default: () => [] },
});

const rows = computed(() => props.logs.data ?? []);
</script>

<template>
    <AdminLayout :title="$t('User activity')">
        <template #nav>
            <Link href="/admin/activity-log" class="hover:text-brand-600">
                {{ $t('All activity') }}
            </Link>
        </template>

        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium text-ink">
                        #{{ targetUser.id }} — {{ targetUser.name }}
                    </p>
                    <p class="text-xs text-ink-soft">{{ targetUser.email }}</p>
                </div>
                <HoursFilter
                    :hours="availableHours"
                    :selected="selectedHours"
                    :base-url="`/admin/activity-log/users/${targetUser.id}`"
                />
            </div>

            <div class="rounded-sm border border-border bg-surface p-3">
                <ActivityLogTable :logs="rows" :show-event-group="true" :link-user="false" />
            </div>

            <PagePaginator />
        </div>
    </AdminLayout>
</template>
