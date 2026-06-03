<script setup>
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * Renders a page of activity entries: a table on desktop, expandable cards on
 * mobile (phase 2.1).
 *
 * Security: every field is rendered through TEXT interpolation, never v-html.
 * The donor built tooltip markup with v-html over user-controlled fields
 * (user_agent, full_url, device name) — a stored-XSS vector. Here those values
 * are plain text, so a crafted user-agent cannot inject markup.
 *
 * `showEventGroup` toggles the event-group column (shown on the per-user view).
 */
defineProps({
    logs: { type: Array, default: () => [] },
    showEventGroup: { type: Boolean, default: false },
    linkUser: { type: Boolean, default: true },
});

const expanded = ref(null);

function toggle(id) {
    expanded.value = expanded.value === id ? null : id;
}

function statusClass(log) {
    return log.is_successful
        ? 'bg-success-soft text-success'
        : 'bg-danger-soft text-danger';
}
</script>

<template>
    <div>
        <!-- Desktop table -->
        <table class="hidden w-full border-collapse text-sm md:table">
            <thead>
                <tr class="border-b border-border text-left text-ink-soft">
                    <th class="py-2 pr-3">{{ $t('Date') }}</th>
                    <th class="py-2 pr-3">{{ $t('IP') }}</th>
                    <th class="py-2 pr-3">{{ $t('User') }}</th>
                    <th v-if="showEventGroup" class="py-2 pr-3">{{ $t('Group') }}</th>
                    <th class="py-2 pr-3">{{ $t('Event') }}</th>
                    <th class="py-2 pr-3 text-right">{{ $t('Result') }}</th>
                    <th class="py-2"></th>
                </tr>
            </thead>
            <tbody>
                <template v-for="log in logs" :key="log.id">
                    <tr class="border-b border-border align-top">
                        <td class="py-2 pr-3 whitespace-nowrap text-ink" :title="log.formatted_date">
                            {{ log.human_date }}
                        </td>
                        <td class="py-2 pr-3 text-ink-soft">{{ log.user_ip || '—' }}</td>
                        <td class="py-2 pr-3">
                            <Link
                                v-if="linkUser && log.causer_id"
                                :href="`/admin/activity-log/users/${log.causer_id}`"
                                class="text-brand-600 hover:underline"
                            >
                                {{ log.user?.name || `#${log.causer_id}` }}
                            </Link>
                            <span v-else class="text-ink-soft">
                                {{ (log.user_email && log.user_email[0]) || $t('Guest') }}
                            </span>
                        </td>
                        <td v-if="showEventGroup" class="py-2 pr-3 text-ink-soft">{{ log.event_group || '—' }}</td>
                        <td class="py-2 pr-3 text-ink">{{ log.description }}</td>
                        <td class="py-2 pr-3 text-right">
                            <span :class="['inline-flex rounded-sm px-2 py-0.5 text-xs font-medium', statusClass(log)]">
                                {{ log.response_code }}
                            </span>
                        </td>
                        <td class="py-2 text-right">
                            <button
                                type="button"
                                class="text-xs text-brand-600 hover:underline"
                                @click="toggle(log.id)"
                            >
                                {{ expanded === log.id ? $t('Hide') : $t('Details') }}
                            </button>
                        </td>
                    </tr>
                    <tr v-if="expanded === log.id" class="border-b border-border bg-surface">
                        <td colspan="7" class="px-3 py-3">
                            <dl class="grid grid-cols-1 gap-x-8 gap-y-1 text-xs text-ink-soft sm:grid-cols-2">
                                <div><dt class="inline font-medium text-ink">{{ $t('Device') }}:</dt> {{ [log.user_device_name, log.user_os, log.user_browser].filter(Boolean).join(' · ') || '—' }}</div>
                                <div><dt class="inline font-medium text-ink">{{ $t('Location') }}:</dt> {{ [log.geo_city, log.geo_country].filter(Boolean).join(', ') || '—' }}</div>
                                <div><dt class="inline font-medium text-ink">{{ $t('Route') }}:</dt> {{ log.route_name || '—' }}</div>
                                <div><dt class="inline font-medium text-ink">{{ $t('Method') }}:</dt> {{ log.request_method || '—' }}</div>
                                <div class="sm:col-span-2 break-all"><dt class="inline font-medium text-ink">{{ $t('URL') }}:</dt> {{ log.full_url || '—' }}</div>
                                <div class="sm:col-span-2 break-all"><dt class="inline font-medium text-ink">{{ $t('User agent') }}:</dt> {{ log.user_agent || '—' }}</div>
                                <div v-if="log.properties && Object.keys(log.properties).length" class="sm:col-span-2">
                                    <dt class="font-medium text-ink">{{ $t('Properties') }}:</dt>
                                    <pre class="mt-1 overflow-x-auto rounded-sm bg-surface-muted p-2 text-[11px]">{{ JSON.stringify(log.properties, null, 2) }}</pre>
                                </div>
                            </dl>
                        </td>
                    </tr>
                </template>
                <tr v-if="!logs.length">
                    <td :colspan="showEventGroup ? 7 : 6" class="py-6 text-center text-ink-soft">
                        {{ $t('No activity in this window.') }}
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Mobile cards -->
        <div class="space-y-3 md:hidden">
            <article
                v-for="log in logs"
                :key="log.id"
                class="rounded-sm border border-border bg-surface p-3"
            >
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="text-sm font-medium text-ink">{{ log.description }}</p>
                        <p class="text-xs text-ink-soft" :title="log.formatted_date">{{ log.human_date }}</p>
                    </div>
                    <span :class="['inline-flex rounded-sm px-2 py-0.5 text-xs font-medium', statusClass(log)]">
                        {{ log.response_code }}
                    </span>
                </div>
                <div class="mt-2 text-xs text-ink-soft">
                    <p>{{ $t('IP') }}: {{ log.user_ip || '—' }}</p>
                    <p>
                        {{ $t('User') }}:
                        <Link
                            v-if="linkUser && log.causer_id"
                            :href="`/admin/activity-log/users/${log.causer_id}`"
                            class="text-brand-600 hover:underline"
                        >{{ log.user?.name || `#${log.causer_id}` }}</Link>
                        <span v-else>{{ (log.user_email && log.user_email[0]) || $t('Guest') }}</span>
                    </p>
                </div>
                <button
                    type="button"
                    class="mt-2 text-xs text-brand-600 hover:underline"
                    @click="toggle(log.id)"
                >
                    {{ expanded === log.id ? $t('Hide') : $t('Details') }}
                </button>
                <dl v-if="expanded === log.id" class="mt-2 space-y-1 text-xs text-ink-soft">
                    <div><dt class="inline font-medium text-ink">{{ $t('Device') }}:</dt> {{ [log.user_device_name, log.user_os, log.user_browser].filter(Boolean).join(' · ') || '—' }}</div>
                    <div><dt class="inline font-medium text-ink">{{ $t('Location') }}:</dt> {{ [log.geo_city, log.geo_country].filter(Boolean).join(', ') || '—' }}</div>
                    <div class="break-all"><dt class="inline font-medium text-ink">{{ $t('URL') }}:</dt> {{ log.full_url || '—' }}</div>
                </dl>
            </article>
            <p v-if="!logs.length" class="py-6 text-center text-sm text-ink-soft">
                {{ $t('No activity in this window.') }}
            </p>
        </div>
    </div>
</template>
