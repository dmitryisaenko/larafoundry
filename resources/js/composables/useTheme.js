/**
 * Theme application layer (the SPA half of dark mode).
 *
 * A host ships the dark palette (token overrides under `html.dark`) and a root
 * template that resolves the persisted `theme` preference on the FIRST paint, so
 * a full page load never flashes the wrong theme. What that first-paint path
 * cannot do is react while the SPA is running: when the user changes their theme
 * in the Appearance/Preferences tab it is persisted to `ui_settings` and Inertia
 * refreshes the shared prop, but the root template never re-runs — so the class
 * never flipped without a manual reload.
 *
 * This composable closes that gap. It keeps `<html>`'s `dark` class (and the
 * mirrored `data-theme` attribute) in sync with the live preference: re-applying
 * after every Inertia visit and when the OS colour scheme changes while the
 * preference is `system`.
 *
 * The core theme itself stays LIGHT-ONLY (neutral for the whole ecosystem); the
 * `dark` class it toggles is a no-op until a host defines its own token overrides
 * under `html.dark`. Convention (class = `dark`) matches Tailwind's class-based
 * dark variant.
 */
import { router } from '@inertiajs/vue3';

function prefersDark() {
    return typeof window !== 'undefined'
        && typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-color-scheme: dark)').matches === true;
}

/** Resolve a preference token to a concrete "is dark" boolean. */
function isDark(pref) {
    if (pref === 'dark') return true;
    if (pref === 'light') return false;

    // 'system' / 'auto' / anything unknown → follow the OS.
    return prefersDark();
}

/** The theme preference carried by a set of Inertia page props. */
function readPreference(props) {
    const settings = props?.ui_settings;
    return (settings && settings.theme) || props?.appearance || 'system';
}

// The preference currently applied, so the OS-change listener knows whether it
// should react (only while the user is on 'system'/'auto').
let currentPref = 'system';
let installed = false;

/** Apply a preference to the document root (idempotent). */
function apply(pref) {
    if (typeof document === 'undefined') {
        return;
    }

    const el = document.documentElement;
    el.dataset.theme = pref;
    el.classList.toggle('dark', isDark(pref));
}

/**
 * Install the theme applier once. Safe to call on every boot — a repeat call is a
 * no-op beyond re-applying the current preference.
 *
 * @param {object} pageProps `props.initialPage.props` from the Inertia setup
 */
export function setupTheme(pageProps = {}) {
    if (typeof document === 'undefined') {
        return;
    }

    currentPref = readPreference(pageProps);
    apply(currentPref);

    if (installed) {
        return;
    }
    installed = true;

    // Follow the OS while the preference is 'system'/'auto'.
    if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        const onChange = () => {
            if (currentPref === 'system' || currentPref === 'auto') {
                apply(currentPref);
            }
        };
        // addEventListener is the modern API; guard for very old Safari.
        if (typeof mq.addEventListener === 'function') {
            mq.addEventListener('change', onChange);
        } else if (typeof mq.addListener === 'function') {
            mq.addListener(onChange);
        }
    }

    // Re-apply after every successful Inertia visit. This is what makes an
    // Appearance-tab theme change take effect live: the PUT persists the new
    // preference and returns fresh `ui_settings`, but the root template never
    // re-runs — so we read the new preference off the visit's page props here.
    router.on('success', (event) => {
        const next = readPreference(event?.detail?.page?.props);
        currentPref = next;
        apply(next);
    });
}

/**
 * Accessor for manual control (e.g. a host toggle button that wants to apply a
 * theme without a round-trip before persisting it).
 */
export function useTheme() {
    return { setupTheme, applyTheme: apply };
}
