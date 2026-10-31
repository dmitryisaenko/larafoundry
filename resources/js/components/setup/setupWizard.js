/**
 * The setup wizard's front-end seams (v0.49.0).
 *
 * The server decides WHICH steps are asked and WHEN (Setup\Support\SetupWizard);
 * the shared `setup_wizard` prop is the list of step keys for this page, or false.
 * The core's dialog draws its own four (theme, locale, about, date) and every step
 * registered here; a step the server asks but nobody registered is left out.
 */

/**
 * Host steps by key: { component, title } - `title` is an i18n key. The component
 * gets the wizard's opening state as the `state` prop and saves through its own
 * endpoint, the way the core's steps do.
 */
export const setupSteps = {};

/**
 * Register (or replace) a step the core's dialog draws. Called once at boot.
 *
 * @param {string} key  the step key the server registered with `addStep()`
 * @param {{ component: import('vue').Component, title: string }} step
 */
export function registerSetupStep(key, step) {
    setupSteps[key] = step;
}

/** Laravel's XSRF token from its cookie - the core ships no axios. */
function xsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Call one of the wizard's endpoints: 'state' (GET) or 'later' / 'done' / 'again'
 * (POST). Resolves with the JSON answer; rejects with the HTTP status on a refusal,
 * so the caller can say a 419 (expired session) apart from the rest.
 *
 * @param {'state'|'later'|'done'|'again'} action
 * @returns {Promise<object>}
 */
export async function setupWizardCall(action) {
    const response = await fetch(`/setup-wizard/${action}`, {
        method: action === 'state' ? 'GET' : 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
    });

    if (!response.ok) {
        throw response.status;
    }

    return response.json();
}
