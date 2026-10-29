/**
 * Page resolution with fallback to the core.
 *
 * The core's Inertia pages no longer have to be published into the host. A host
 * resolves a page name against its OWN pages first and falls back to the core's
 * copy in `vendor/`, so it keeps only the pages it actually changed (an override is
 * simply a host file at the same path) and every other page follows core upgrades
 * with no re-publish.
 *
 * Deliberately NOT exported from the barrel: the glob below emits one lazy chunk per
 * core page, and a host that still publishes the pages must not pay for that. Import
 * it by subpath (the `@dmitryisaenko/larafoundry/resources` alias, as QrScanner).
 *
 *     import { resolvePage } from '@dmitryisaenko/larafoundry/resources/js/pages.js';
 *     resolve: (name) => resolvePage(name, import.meta.glob('./Pages/**\/*.vue')),
 */

// Resolved by Vite relative to THIS file, i.e. the core's own Pages folder.
export const corePages = import.meta.glob('./Pages/**/*.vue');

/**
 * Find the loader for a page: the host's file wins, the core's is the fallback.
 *
 * @param {string} name  Inertia page name, e.g. 'Tickets/Index'
 * @param {Record<string, Function>} hostPages  the host's `import.meta.glob('./Pages/**\/*.vue')`
 * @returns {Function|undefined}
 */
export function pageLoader(name, hostPages = {}) {
    return hostPages[`./Pages/${name}.vue`] ?? corePages[`./Pages/${name}.vue`];
}

/**
 * Inertia `resolve` callback. Throws on an unknown name, like laravel-vite-plugin's
 * `resolvePageComponent`, so a missing page fails loudly instead of rendering blank.
 *
 * @param {string} name
 * @param {Record<string, Function>} hostPages
 * @returns {Promise<object>}
 */
export function resolvePage(name, hostPages = {}) {
    const loader = pageLoader(name, hostPages);

    if (!loader) {
        throw new Error(`Page not found: ${name} (neither in the host's Pages nor in the core's)`);
    }

    return loader();
}
