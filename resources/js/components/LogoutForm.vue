<script setup>
/**
 * Logout via a NATIVE form POST (full-page reload), not an Inertia visit.
 *
 * An Inertia logout stays inside the SPA: after the session is cleared the server
 * redirects to the guest landing, and if that landing is a plain (non-Inertia)
 * response — as SEO-first host landings are — Inertia has no valid response to
 * render and pops its error-modal overlay over the page. A native form submit is a
 * full-page navigation: the browser follows the 302 and reloads from scratch, so
 * Inertia never intercepts the logout and no overlay can appear.
 *
 * CSRF: the token is read from `<meta name="csrf-token">`, which the host shell
 * MUST render in its blade `<head>` (documented contract — see
 * docs/integrating-into-an-existing-app.md). Without it the POST returns 419.
 *
 * The default slot is the button's inner content (label, icon); `buttonClass`
 * styles the button. `display:contents` on the form keeps this wrapper out of the
 * parent layout, so it drops in wherever the old <Link> button stood.
 */
defineProps({
    buttonClass: { type: String, default: '' },
});

// Guarded for SSR: a host that renders Inertia server-side has no `document`,
// and the token is only needed in the browser where the form actually submits.
const csrf = typeof document !== 'undefined'
    ? (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '')
    : '';
</script>

<template>
    <form method="POST" action="/logout" class="contents">
        <input type="hidden" name="_token" :value="csrf">
        <button type="submit" :class="buttonClass">
            <slot />
        </button>
    </form>
</template>
