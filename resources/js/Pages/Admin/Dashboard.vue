<script setup>
/**
 * Super-admin operator-console dashboard (phase 3.4) — the console landing
 * screen. Super-admin only (gated server-side by `larafoundry.admin`).
 *
 * The backend builds and permission-filters the widget list (DashboardBuilder),
 * so this page is purely a renderer: it resolves each widget's component NAME
 * through the pluggable registry and lays the widgets out in a grid. A name the
 * registry does not know (an add-on widget whose registrar a host forgot to
 * import) falls back to UnknownWidget rather than breaking the page — the same
 * defensive idiom NavIcon uses for an unknown icon.
 */
import { computed } from 'vue';
import { AdminLayout, dashboardWidgets } from '@dmitryisaenko/larafoundry';

const props = defineProps({
    widgets: { type: Array, default: () => [] },
});

// The backend already sorts by order; re-sort defensively so a registry-resolved
// widget can never appear out of order.
const ordered = computed(() => [...props.widgets].sort((a, b) => (a.order ?? 100) - (b.order ?? 100)));

function resolve(name) {
    return dashboardWidgets[name] ?? dashboardWidgets.UnknownWidget;
}

function spanClass(widget) {
    return widget.meta?.span === 2 ? 'xl:col-span-2' : '';
}
</script>

<template>
    <AdminLayout :title="$t('Dashboard')">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <component
                :is="resolve(widget.component)"
                v-for="widget in ordered"
                :key="widget.key"
                :class="spanClass(widget)"
                :title-key="widget.titleKey"
                :data="widget.data"
                :meta="widget.meta"
            />
        </div>
    </AdminLayout>
</template>
