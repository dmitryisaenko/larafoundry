<script setup>
/**
 * Renders an email body preview inside a sandboxed iframe (phase 5.1).
 *
 * The html is server-rendered and server-purified (the editor POSTs to the
 * preview endpoint, which runs the same HtmlSanitizer as a save), so the markup
 * here is already clean. The `sandbox` attribute is an INDEPENDENT second
 * barrier: it deliberately omits `allow-scripts`, so even if anything slipped
 * through the purifier, no script in the previewed body can execute or reach the
 * parent app. `allow-same-origin` is granted only so the iframe can render
 * its own document; without `allow-scripts` it cannot script the parent.
 */
defineProps({
    html: { type: String, default: '' },
});
</script>

<template>
    <iframe
        :srcdoc="html"
        sandbox="allow-same-origin"
        class="h-96 w-full rounded-md border border-border bg-white"
        title="Email preview"
    />
</template>
