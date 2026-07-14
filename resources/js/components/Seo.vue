<script setup>
/**
 * Client-side SEO head (phase 5.2).
 *
 * Re-emits the meta/link tags from the `seo` shared Inertia prop through
 * Inertia's <Head>, so SPA navigation keeps the document title, description,
 * canonical, robots, Open Graph and Twitter tags in step without a full reload.
 * The SERVER-rendered head (the crawler/social path) is the `@larafoundrySeo`
 * Blade directive — this component is purely the SPA-navigation counterpart.
 *
 * Every tag carries a stable `head-key` so Inertia replaces (never duplicates)
 * it across visits. Renders nothing when the host has not wired the `seo` prop
 * yet, so mounting it early is always safe. URLs are scheme-guarded before they
 * reach an href, mirroring the server renderer.
 *
 * Mounted once by the core layouts (each page renders exactly one shell), so
 * there is a single instance per page.
 */
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';

const page = usePage();

const seo = computed(() => page.props?.seo ?? null);

function isHttp(url) {
    return typeof url === 'string' && /^https?:\/\//i.test(url);
}

// hreflang alternates as a filtered list ({ locale, url }) — v-for cannot sit on
// the same node as v-if, so the http(s) guard is applied here.
const hreflangs = computed(() => {
    const map = seo.value?.hreflangs ?? {};

    return Object.entries(map)
        .filter(([, url]) => isHttp(url))
        .map(([locale, url]) => ({ locale, url }));
});
</script>

<template>
    <Head v-if="seo">
        <title v-if="seo.title">{{ seo.title }}</title>

        <template v-if="seo.title">
            <meta head-key="og:title" property="og:title" :content="seo.title" />
            <meta head-key="twitter:title" name="twitter:title" :content="seo.title" />
        </template>

        <template v-if="seo.description">
            <meta head-key="description" name="description" :content="seo.description" />
            <meta head-key="og:description" property="og:description" :content="seo.description" />
            <meta head-key="twitter:description" name="twitter:description" :content="seo.description" />
        </template>

        <template v-if="isHttp(seo.canonical)">
            <link head-key="canonical" rel="canonical" :href="seo.canonical" />
            <meta head-key="og:url" property="og:url" :content="seo.canonical" />
        </template>

        <meta head-key="robots" name="robots" :content="seo.robots" />
        <meta head-key="og:type" property="og:type" :content="seo.og?.type" />

        <template v-if="isHttp(seo.og?.image)">
            <meta head-key="og:image" property="og:image" :content="seo.og.image" />
            <meta head-key="twitter:image" name="twitter:image" :content="seo.og.image" />
        </template>

        <meta v-if="seo.og?.site_name" head-key="og:site_name" property="og:site_name" :content="seo.og.site_name" />

        <meta head-key="twitter:card" name="twitter:card" :content="seo.twitter?.card" />
        <meta v-if="seo.twitter?.site" head-key="twitter:site" name="twitter:site" :content="seo.twitter.site" />

        <link
            v-for="entry in hreflangs"
            :key="entry.locale"
            :head-key="`hreflang:${entry.locale}`"
            rel="alternate"
            :hreflang="entry.locale"
            :href="entry.url"
        />
    </Head>
</template>
