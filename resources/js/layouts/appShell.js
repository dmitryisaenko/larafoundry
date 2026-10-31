/**
 * The host's own signed-in shell (v0.49.0).
 *
 * A host with a shell of its own registers it once at boot; from then on every
 * core page drawn in `AppLayout` (the profile hub, account settings, billing)
 * wears the host's shell instead of the core's, so the host keeps no copy of a
 * core page only to change its frame. The host's shell then owns everything
 * AppLayout would mount - the setup wizard's dialog included.
 */
export const appShell = { component: null };

/**
 * @param {import('vue').Component} component  a layout rendering its default slot
 */
export function registerAppShell(component) {
    appShell.component = component;
}
