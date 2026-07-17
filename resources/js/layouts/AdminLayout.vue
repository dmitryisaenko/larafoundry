<script setup>
/**
 * Super-admin (operator console) shell.
 *
 * Born minimal in phase 2.1 (a header band framing the activity log); phase 2.3
 * fills it out with the console sidebar. The menu tree comes pre-filtered from
 * the backend via the `navigation` shared prop (decision D-nav-a) — the whole
 * zone is already behind the `larafoundry.admin` gate, so the admin menu items
 * carry no per-item policy.
 *
 * Chosen by {@see LayoutSwitcher} for the super-admin visitor status. The
 * `title` prop labels the current section; the `nav` slot is kept for ad-hoc
 * header links (back-compat with the 2.1 shape).
 */
import { computed, ref, watch } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import AppFlashMessage from '../components/AppFlashMessage.vue';
import Seo from '../components/Seo.vue';
import SidebarNav from '../components/navigation/SidebarNav.vue';
import MobileNav from '../components/navigation/MobileNav.vue';
import NotificationBell from '../components/notifications/NotificationBell.vue';
import OperatorPullout from '../components/admin/OperatorPullout.vue';
import ImpersonationBanner from '../components/admin/ImpersonationBanner.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';

defineProps({
    title: { type: String, default: '' },
});

const page = usePage();

const navigation = computed(() => page.props.navigation ?? []);

// Collapsible sidebar (icon-rail). Seeded from the operator's `sidebar_collapsed`
// UI preference (shared globally), so the choice survives reloads. Toggling
// persists it through the same allowlisted endpoint the Appearance tab uses
// (`profile.ui-settings.update` is in the super-admin allow-list), then flips the
// local state optimistically so the rail animates without waiting on the round-trip.
const collapsed = ref(page.props.ui_settings?.sidebar_collapsed === true);

function toggleSidebar() {
    collapsed.value = !collapsed.value;
    router.put(
        '/profile/ui-settings',
        { key: 'sidebar_collapsed', value: collapsed.value },
        { preserveScroll: true, preserveState: true },
    );
}

// Keep the rail in sync if the same preference is changed elsewhere (the
// Preferences tab's "Collapse sidebar" toggle writes the same shared prop).
watch(
    () => page.props.ui_settings?.sidebar_collapsed,
    (next) => { collapsed.value = next === true; },
);
</script>

<template>
    <div class="flex min-h-screen flex-col bg-surface-muted text-ink">
        <Seo />
        <ImpersonationBanner />
        <AppFlashMessage />

        <header class="sticky top-0 z-30 border-b border-border bg-surface">
            <div class="mx-auto flex w-full max-w-[var(--lf-max-width)] items-center justify-between px-4 py-3">
                <div class="flex items-center gap-3">
                    <MobileNav :items="navigation" />
                    <span class="rounded-sm bg-brand-700 px-2 py-1 text-xs font-semibold uppercase tracking-wide text-white">
                        {{ $t('Super-admin') }}
                    </span>
                    <h1 v-if="title" class="text-lg font-semibold text-ink">{{ title }}</h1>
                </div>
                <nav class="flex items-center gap-4 text-sm text-ink-soft">
                    <NotificationBell />
                    <slot name="nav" />
                    <OperatorPullout />
                </nav>
            </div>
        </header>

        <div class="mx-auto flex w-full max-w-[var(--lf-max-width)] flex-1 gap-6 px-4 py-6">
            <aside
                class="hidden shrink-0 transition-[width] duration-200 md:block"
                :class="collapsed ? 'md:w-16' : 'md:w-60'"
            >
                <button
                    type="button"
                    class="mb-2 flex w-full items-center rounded-sm px-2 py-2 text-sm text-ink-soft transition hover:bg-surface-subtle hover:text-ink"
                    :class="collapsed ? 'justify-center' : 'justify-end'"
                    :title="collapsed ? $t('Expand sidebar') : $t('Collapse sidebar')"
                    :aria-label="collapsed ? $t('Expand sidebar') : $t('Collapse sidebar')"
                    :aria-pressed="collapsed"
                    @click="toggleSidebar"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="1.5"
                        stroke="currentColor"
                        class="h-5 w-5 shrink-0 transition-transform duration-200"
                        :class="collapsed ? 'rotate-180' : ''"
                        aria-hidden="true"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18.75 4.5 11.25 12l7.5 7.5m-9-15L2.25 12l7.5 7.5" />
                    </svg>
                </button>
                <SidebarNav :items="navigation" :collapsed="collapsed" />
            </aside>

            <main class="min-w-0 flex-1">
                <slot />
            </main>
        </div>

        <!-- App-wide confirm dialog (singleton, driven by the confirm() API). -->
        <ConfirmDialog />
    </div>
</template>
