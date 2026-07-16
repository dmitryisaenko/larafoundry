/**
 * LaraFoundry core — frontend entry point.
 *
 * Host apps wire the core into their Inertia setup with a single call:
 *
 *     import { createLaraFoundry } from '@dmitryisaenko/larafoundry';
 *
 *     setup({ el, App, props, plugin }) {
 *         const app = createApp({ render: () => h(App, props) }).use(plugin);
 *         createLaraFoundry(app, props.initialPage.props);
 *         app.mount(el);
 *     }
 *
 * This installs i18n (with global `$t` / `globalThis.t`) and registers the
 * core's globally-available components. Ziggy and the Inertia plugin stay the
 * host's responsibility — the core does not assume how routing is provided.
 */

import { installI18n } from './i18n/index.js';

export { useT } from './composables/useT.js';
export { useDateFormat } from './composables/useDateFormat.js';
export { formatDate, formatDateTime } from './composables/dateFormat.js';
export { installI18n, createAppI18n } from './i18n/index.js';

// Shared components
export { default as AppFlashMessage } from './components/AppFlashMessage.vue';
export { default as PagePaginator } from './components/PagePaginator.vue';
export { default as AuthCard } from './components/AuthCard.vue';
export { default as AuthScreen } from './components/auth/AuthScreen.vue';
export { default as Modal } from './components/ui/Modal.vue';
export { default as QrLoginPanel } from './components/auth/QrLoginPanel.vue';
// QrScanner is intentionally NOT re-exported here. It pulls in the heavy
// `html5-qrcode` camera library via a lazy import, and re-exporting it from the
// barrel would drag that dependency into the build graph of every host that
// imports the core entry point — even hosts that never build a scanner page.
// A host that does build the scanning device's page imports it directly:
//   import QrScanner from '@dmitryisaenko/larafoundry/resources/js/components/auth/QrScanner.vue';
// and adds `html5-qrcode` to its own package.json (the page that uses it owns
// the runtime dependency).
export { default as TwoFactorChallengeForm } from './components/auth/TwoFactorChallengeForm.vue';
export { default as CompanySwitcher } from './components/CompanySwitcher.vue';
export { default as LocaleSwitcher } from './components/LocaleSwitcher.vue';
export { default as PermissionsSelector } from './components/PermissionsSelector.vue';
export { default as ActivityLogTable } from './components/activitylog/ActivityLogTable.vue';
export { default as HoursFilter } from './components/activitylog/HoursFilter.vue';

// Navigation (phase 2.3)
export { default as SidebarNav } from './components/navigation/SidebarNav.vue';
export { default as NavItem } from './components/navigation/NavItem.vue';
export { default as NavIcon } from './components/navigation/NavIcon.vue';
export { default as MobileNav } from './components/navigation/MobileNav.vue';

// Admin console (phase 2.3)
export { default as UsersTable } from './components/admin/UsersTable.vue';
export { default as UsersTableActions } from './components/admin/UsersTableActions.vue';
export { default as BlockUserDialog } from './components/admin/BlockUserDialog.vue';
export { default as AdminFilterDrawer } from './components/admin/AdminFilterDrawer.vue';
export { default as ImpersonationBanner } from './components/admin/ImpersonationBanner.vue';
// Admin user forms — social links (phase 3b)
export { default as SocialLinksField } from './components/admin/SocialLinksField.vue';
export { default as SocialIcon } from './components/admin/SocialIcon.vue';

// Admin companies (phase 3.3)
export { default as CompaniesTable } from './components/admin/CompaniesTable.vue';
export { default as CompaniesTableActions } from './components/admin/CompaniesTableActions.vue';
export { default as SubscriptionStatusBadge } from './components/admin/SubscriptionStatusBadge.vue';

// Dashboard widgets (phase 3.4)
import UsersWidget from './components/admin/dashboard/UsersWidget.vue';
import CompaniesWidget from './components/admin/dashboard/CompaniesWidget.vue';
import ActivityWidget from './components/admin/dashboard/ActivityWidget.vue';
import UnknownWidget from './components/admin/dashboard/UnknownWidget.vue';

export { UsersWidget, CompaniesWidget, ActivityWidget, UnknownWidget };

// Media (phase 2.4)
export { default as UserAvatar } from './components/media/UserAvatar.vue';
export { default as CompanyLogo } from './components/media/CompanyLogo.vue';
export { default as FileUpload } from './components/media/FileUpload.vue';
export { default as ImageUpload } from './components/media/ImageUpload.vue';

// Notifications (phase 4.1)
export { default as NotificationBell } from './components/notifications/NotificationBell.vue';
export { default as NotificationItem } from './components/notifications/NotificationItem.vue';
export { default as BroadcastForm } from './components/notifications/BroadcastForm.vue';

// Tickets / helpdesk (phase 4.2)
export { default as TicketStatusBadge } from './components/tickets/TicketStatusBadge.vue';
export { default as TicketPriorityBadge } from './components/tickets/TicketPriorityBadge.vue';
export { default as TicketMessageList } from './components/tickets/TicketMessageList.vue';
export { default as TicketForm } from './components/tickets/TicketForm.vue';
export { default as SupportLink } from './components/tickets/SupportLink.vue';

// Settings (phase 5.1)
export { default as SettingsForm } from './components/settings/SettingsForm.vue';

// Email templates (phase 5.1, two-layer CRUD in phase 2b)
export { default as EmailTemplateEditor } from './components/email/EmailTemplateEditor.vue';
export { default as MarketingEmailTemplateForm } from './components/email/MarketingEmailTemplateForm.vue';
export { default as EmailPreviewFrame } from './components/email/EmailPreviewFrame.vue';

// Legal / GDPR (phase 5.3)
export { default as LegalPageEditor } from './components/legal/LegalPageEditor.vue';
export { default as CookieConsentBanner } from './components/legal/CookieConsentBanner.vue';

// SEO kit (phase 5.2) — the core layouts mount <Seo> once; a host using a bare
// layout can import and mount it itself.
export { default as Seo } from './components/Seo.vue';

// Onboarding (Ф5.4) — the host places <OnboardingChecklist> on its own home
// page; the core does NOT mount it (unlike <Seo>). It renders nothing when the
// checklist is dismissed, complete or absent, so it is always safe to include.
export { default as OnboardingChecklist } from './components/OnboardingChecklist.vue';

// Form UI-kit
export { default as InputField } from './components/ui/InputField.vue';
export { default as TextareaField } from './components/ui/TextareaField.vue';
export { default as SelectField } from './components/ui/SelectField.vue';
export { default as DateField } from './components/ui/DateField.vue';

// Confirm dialog (SweetAlert-style, dependency-free): a singleton dialog driven
// by the promise-based `confirm()` API. Mount <ConfirmDialog/> once per app (the
// core layouts already do); call `confirm(options)` from anywhere.
export { default as ConfirmDialog } from './components/ui/ConfirmDialog.vue';
export { confirm, useConfirmState } from './composables/useConfirm.js';

// Layouts
export { default as AppBaseLayout } from './layouts/AppBaseLayout.vue';
export { default as AppLayout } from './layouts/AppLayout.vue';
export { default as AdminLayout } from './layouts/AdminLayout.vue';
export { default as LayoutSwitcher } from './layouts/LayoutSwitcher.vue';

/**
 * Pluggable dashboard widget registry (phase 3.4).
 *
 * Maps a widget COMPONENT NAME (the string the backend DashboardWidget ships) to
 * its Vue component. The dashboard page resolves each widget through this map, so
 * the seam grows without the page knowing every widget: the core registers its
 * own here, and an add-on (or host) registers more at boot via
 * {@link registerDashboardWidget}. `UnknownWidget` is the fallback the page uses
 * for a name not in the map.
 */
export const dashboardWidgets = {
    UsersWidget,
    CompaniesWidget,
    ActivityWidget,
    UnknownWidget,
};

/**
 * Register (or override) a dashboard widget component by name.
 *
 * A host imports an add-on's registrar once in its app entry (e.g.
 * `import '@/Pages/Admin/Dashboard/registerBillingWidgets'`), which calls this so
 * the add-on's backend-declared widget has a component to render. Called at boot,
 * before any dashboard render.
 *
 * @param {string} name  the component name the backend widget uses
 * @param {import('vue').Component} component
 */
export function registerDashboardWidget(name, component) {
    dashboardWidgets[name] = component;
}

/**
 * Install the LaraFoundry core into a Vue app instance.
 *
 * @param {import('vue').App} app
 * @param {object} pageProps  `props.initialPage.props` from Inertia setup
 * @returns {{ i18n: import('vue-i18n').I18n }}
 */
export function createLaraFoundry(app, pageProps = {}) {
    const i18n = installI18n(app, pageProps);

    return { i18n };
}
