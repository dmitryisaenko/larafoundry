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
export { installI18n, createAppI18n } from './i18n/index.js';

// Shared components
export { default as AppFlashMessage } from './components/AppFlashMessage.vue';
export { default as PagePaginator } from './components/PagePaginator.vue';
export { default as AuthCard } from './components/AuthCard.vue';
export { default as CompanySwitcher } from './components/CompanySwitcher.vue';
export { default as PermissionsSelector } from './components/PermissionsSelector.vue';

// Form UI-kit
export { default as InputField } from './components/ui/InputField.vue';
export { default as TextareaField } from './components/ui/TextareaField.vue';
export { default as SelectField } from './components/ui/SelectField.vue';
export { default as DateField } from './components/ui/DateField.vue';

// Layouts
export { default as AppBaseLayout } from './layouts/AppBaseLayout.vue';
export { default as AdminLayout } from './layouts/AdminLayout.vue';

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
