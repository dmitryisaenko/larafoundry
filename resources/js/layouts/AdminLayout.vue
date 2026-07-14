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
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppFlashMessage from '../components/AppFlashMessage.vue';
import Seo from '../components/Seo.vue';
import SidebarNav from '../components/navigation/SidebarNav.vue';
import MobileNav from '../components/navigation/MobileNav.vue';
import NotificationBell from '../components/notifications/NotificationBell.vue';
import ImpersonationBanner from '../components/admin/ImpersonationBanner.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';

defineProps({
    title: { type: String, default: '' },
});

const page = usePage();

const navigation = computed(() => page.props.navigation ?? []);
</script>

<template>
    <div class="flex min-h-screen flex-col bg-surface-muted text-ink">
        <Seo />
        <ImpersonationBanner />
        <AppFlashMessage />

        <header class="border-b border-border bg-surface">
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
                </nav>
            </div>
        </header>

        <div class="mx-auto flex w-full max-w-[var(--lf-max-width)] flex-1 gap-6 px-4 py-6">
            <aside class="hidden w-60 shrink-0 md:block">
                <SidebarNav :items="navigation" />
            </aside>

            <main class="min-w-0 flex-1">
                <slot />
            </main>
        </div>

        <!-- App-wide confirm dialog (singleton, driven by the confirm() API). -->
        <ConfirmDialog />
    </div>
</template>
