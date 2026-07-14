<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LaraFoundry — SEO kit (phase 5.2)
|--------------------------------------------------------------------------
| A lean, convention-matching toolkit for the crawler- and social-visible
| surface: per-request meta (title / description / canonical / robots), Open
| Graph + Twitter card tags, hreflang alternates, a provider-driven sitemap.xml
| and a robots.txt. Nothing here generates images — the OG image is a THIN seam
| (a static path or absolute URL); a host or add-on can rebind the
| OgImageResolver contract to produce dynamic images later.
|
| Two render paths share this config:
|   server-side — the `@larafoundrySeo` Blade directive echoes escaped tags into
|       the host's <head> (the crawler/social path). EVERY value is escaped.
|   client-side — the <Seo> Vue component re-emits the same tags on SPA
|       navigation from the `seo` shared Inertia prop.
|
| A `null` default falls back to the framework's own value (config('app.name') /
| config('app.url')), so the kit works out of the box and the host tunes it by
| publishing this file or setting the LARAFOUNDRY_SEO_* env vars.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    | The per-request fallbacks used when a page does not set its own meta via
    | the SeoManager. `title`/`description` null => fall back to the app name /
    | no description. `robots` is the default directive for ordinary pages;
    | sensitive screens opt into `noindex,nofollow` in code.
    */
    'defaults' => [
        'title' => env('LARAFOUNDRY_SEO_TITLE', null),        // null => fallback to config('app.name')
        'description' => env('LARAFOUNDRY_SEO_DESCRIPTION', null),
        'robots' => env('LARAFOUNDRY_SEO_ROBOTS', 'index,follow'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Canonical
    |--------------------------------------------------------------------------
    | `base_url` overrides the host used to build the canonical URL (and the
    | hreflang alternates). null => fall back to config('app.url'); the current
    | request URL is used when neither is set.
    */
    'canonical' => [
        'base_url' => env('LARAFOUNDRY_SEO_BASE_URL', null),  // null => fallback config('app.url')
    ],

    /*
    |--------------------------------------------------------------------------
    | Open Graph
    |--------------------------------------------------------------------------
    | `default_image` is a static path (resolved via the media disk) or an
    | absolute http(s) URL — the THIN seam. NO image is generated here; rebind
    | the OgImageResolver contract to add dynamic generation.
    */
    'og' => [
        'type' => 'website',
        'site_name' => env('LARAFOUNDRY_SEO_SITE_NAME', null),  // null => config('app.name')
        'default_image' => env('LARAFOUNDRY_SEO_OG_IMAGE', null),  // static path or absolute URL (thin seam)
    ],

    /*
    |--------------------------------------------------------------------------
    | Twitter card
    |--------------------------------------------------------------------------
    | `site` is the @handle for the twitter:site tag (only emitted when set).
    */
    'twitter' => [
        'card' => 'summary_large_image',
        'site' => env('LARAFOUNDRY_SEO_TWITTER_SITE', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | hreflang alternates
    |--------------------------------------------------------------------------
    | When enabled, emit <link rel="alternate" hreflang> for every locale in
    | `larafoundry.locale.available` plus an x-default. The core switches locale
    | by session/cookie (no per-locale URL segment), so the alternates all point
    | at the canonical URL — valid and harmless. A host with per-locale URLs can
    | rebind the SeoManager to emit locale-specific URLs.
    */
    'hreflang' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sitemap
    |--------------------------------------------------------------------------
    | The provider-driven /sitemap.xml. Providers (the core ships one for
    | published legal pages; a host adds its own) contribute entries to the
    | SitemapBuilder. The rendered XML is cached for `cache_ttl` seconds.
    */
    'sitemap' => [
        'enabled' => true,
        'cache_ttl' => (int) env('LARAFOUNDRY_SEO_SITEMAP_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | robots.txt
    |--------------------------------------------------------------------------
    | The public /robots.txt. Kept simple: allow all by default; the Sitemap URL
    | is appended automatically when the sitemap is enabled.
    */
    'robots' => [
        'enabled' => true,
    ],

];
