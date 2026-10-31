<script setup>
/**
 * Tenant application shell — an owner/employee inside a company (phase 2.3).
 *
 * The role-aware counterpart to the bare {@see AppBaseLayout}: a header with
 * the company + locale switchers and a mobile menu trigger, a permission-filtered
 * sidebar, and the page body. The menu tree comes pre-filtered from the backend
 * via the `navigation` shared prop (decision D-nav-a) — this layout only renders
 * it.
 *
 * Chosen by {@see LayoutSwitcher} when the visitor is an authenticated company
 * member with an active company.
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppFlashMessage from '../components/AppFlashMessage.vue';
import Seo from '../components/Seo.vue';
import CompanySwitcher from '../components/CompanySwitcher.vue';
import LocaleSwitcher from '../components/LocaleSwitcher.vue';
import SidebarNav from '../components/navigation/SidebarNav.vue';
import MobileNav from '../components/navigation/MobileNav.vue';
import NotificationBell from '../components/notifications/NotificationBell.vue';
import SupportLink from '../components/tickets/SupportLink.vue';
import ImpersonationBanner from '../components/admin/ImpersonationBanner.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import SetupWizardDialog from '../components/setup/SetupWizardDialog.vue';
import { appShell } from './appShell.js';

const page = usePage();

const navigation = computed(() => page.props.navigation ?? []);
const companies = computed(() => page.props.companies ?? []);
const activeCompany = computed(() => page.props.activeCompany ?? null);
// The first-login wizard's steps over this page, or false (v0.49.0, Setup\Support\SetupWizard).
const setupSteps = computed(() => (Array.isArray(page.props.setup_wizard) ? page.props.setup_wizard : []));
// A host's own shell, when it registered one (registerAppShell) - it then draws the frame.
const hostShell = appShell.component;
</script>

<template>
    <component :is="hostShell" v-if="hostShell">
        <slot />
    </component>

    <div v-else class="flex min-h-screen flex-col bg-surface-muted text-ink">
        <Seo />
        <ImpersonationBanner />
        <AppFlashMessage />

        <header class="border-b border-border bg-surface">
            <div class="mx-auto flex w-full max-w-[var(--lf-max-width)] items-center justify-between gap-3 px-4 py-3">
                <div class="flex items-center gap-3">
                    <MobileNav :items="navigation" />
                    <slot name="brand" />
                </div>
                <div class="flex items-center gap-3">
                    <CompanySwitcher :companies="companies" :active="activeCompany" />
                    <LocaleSwitcher />
                    <NotificationBell />
                    <SupportLink />
                    <slot name="header-actions" />
                </div>
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

        <SetupWizardDialog v-if="setupSteps.length" :steps="setupSteps" />
    </div>
</template>
