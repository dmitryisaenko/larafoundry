# SEO kit

The SEO kit gives the Inertia SPA a search-engine and social-media surface without
a Node SSR daemon. One request-scoped `SeoManager` feeds two consumers from a
single source of truth: a **server-rendered `<head>`** for crawlers and social
unfurlers (emitted through a Blade directive the host puts in `app.blade.php`), and
a **shared Inertia prop** the client `<Seo>` component updates on SPA navigation. On
top of the head, the kit ships a sitemap provider registry (the same shape as the
navigation menu seam), public `sitemap.xml` / `robots.txt` routes, hreflang
alternates, and a thin, config-driven Open Graph image seam. A host wires two
things and can extend the sitemap by registering its own provider; it edits nothing
in the core.

## Contents

- [Install](#install)
- [Configuration](#configuration)
- [Usage](#usage)
- [API reference](#api-reference)
- [Security notes](#security-notes)
- [Host integration](#host-integration)
- [Testing](#testing)

## Install

The SEO kit ships with the core package (module `src/Seo/`); there is nothing extra
to require. The `SeoManager` is registered as a request singleton, the
`SitemapBuilder` carries the core's legal-pages provider, and the public
`sitemap.xml` / `robots.txt` routes load automatically from `routes/seo.php`. A host
opts in by:

1. Adding the `@larafoundrySeo` Blade directive inside the `<head>` of its
   `app.blade.php`, so crawlers and social unfurlers get a server-rendered head.
2. Merging the SEO shared prop into its `HandleInertiaRequests::share()` (below), so
   the client `<Seo>` component keeps meta current across SPA navigation.
3. Optionally publishing the config and setting the `LARAFOUNDRY_SEO_*` env, and
   registering one or more `SitemapProviderInterface` classes to add its own URLs to
   the sitemap.

The two required steps are the [Host integration](#host-integration) section below.

## Configuration

The kit ships its own per-module config file, `config/larafoundry-seo.php`, merged
automatically. Publish it only to override the defaults:

```bash
php artisan vendor:publish --tag=larafoundry-seo-config
```

| Key | Default | What it does |
|-----|---------|--------------|
| `defaults.title` / `defaults.description` | app name / null | The title and description used when a page sets none. |
| `defaults.robots` | `index,follow` | The default robots directive. Sensitive Fortify screens override it to `noindex`. |
| `canonical.base_url` | `app.url` | The base every canonical and sitemap `<loc>` is built from. This is deliberately **not** the request host (see [Security notes](#security-notes)). |
| `og.default_image` | null | The static default Open Graph image, resolved through the `OgImageResolver`. Relative paths resolve via the core `MediaStorage` contract. |
| `og.type` | `website` | The default `og:type`. |
| `twitter.card` | `summary_large_image` | The Twitter card type. |
| `hreflang.enabled` | `true` | Whether hreflang alternates are emitted (from `config('larafoundry.locale.available')`). |
| `sitemap.enabled` | `true` | Whether the `sitemap.xml` route is served. |
| `sitemap.cache_ttl` | (config) | How long the rendered sitemap XML is cached. |
| `robots.enabled` | `true` | Whether the `robots.txt` route is served. |

## Usage

### Setting page meta

`SeoManager` is a fluent, request-scoped singleton. Set what a page needs; anything
you leave unset falls back to config or a sensible default:

```php
use Dmitryisaenko\LaraFoundry\Seo\SeoManager;

app(SeoManager::class)
    ->title('Pricing')
    ->description('Simple per-seat pricing.')
    ->canonical(route('pricing'))
    ->ogType('website');
```

The core already sets meta on the screens it owns: the sensitive Fortify screens
(reset-password, confirm-password, verify-email, two-factor-challenge) are set
`noindex`, and each published legal page indexes with its real title.

### The server-rendered head (`@larafoundrySeo`)

`renderHead()` returns the server-rendered `<head>` HTML - title, description,
canonical, Open Graph, Twitter card and hreflang - and is emitted by the
`@larafoundrySeo` Blade directive the host places inside its `app.blade.php` head.
This is the crawler and social-unfurl path: the head is present in the very first
HTML response, so no Node SSR daemon is needed for a bot to read it.

### The client `<Seo>` component

`toArray()` is shipped as the `seo` Inertia shared prop via
`LaraFoundrySeo::sharedProps()`. The `<Seo>` Vue component (from the package barrel)
consumes it and updates the document head on client-side SPA navigation, so a visit
between Inertia pages keeps the title and meta current without a full reload. The
server head and the client prop come from the same `SeoManager`, so they cannot
drift.

### The sitemap and robots routes

`routes/seo.php` registers two public, web-only, unauthenticated routes:

- `sitemap.xml` - built by `SitemapBuilder` from every registered
  `SitemapProviderInterface`. The rendered XML is cached (`sitemap.cache_ttl`).
- `robots.txt` - the robots file, pointing at the sitemap.

The core ships one provider, `LegalSitemapProvider`, which emits only **published**
legal pages (an unpublished page never leaks into the sitemap).

### Registering a host sitemap provider

A provider implements `SitemapProviderInterface` and returns its URLs; register it
on the shared `SitemapBuilder` in a service provider's `boot()`, exactly like a
navigation `MenuProvider`:

```php
use Dmitryisaenko\LaraFoundry\Seo\Contracts\SitemapProviderInterface;
use Dmitryisaenko\LaraFoundry\Seo\Support\SitemapBuilder;

class OrdersSitemapProvider implements SitemapProviderInterface
{
    public function getUrls(): array
    {
        // return your public, indexable URLs
    }
}

// app/Providers/AppServiceProvider.php
public function boot(): void
{
    $this->app->make(SitemapBuilder::class)->addProvider(new OrdersSitemapProvider);
}
```

Every `<loc>` the builder emits is rebuilt from the configured canonical base, so a
host provider does not have to (and should not) hardcode a host.

### The OG-image seam

The Open Graph image is a **thin seam**, not an image generator. The config default
(`larafoundry-seo.og.default_image`) is resolved through a rebindable
`OgImageResolver` contract; the core binds `ConfigOgImageResolver`, which resolves a
relative path through the core `MediaStorage` contract. There is **no dynamic image
generation** in the core. A host that wants per-page generated cards binds its own
`OgImageResolver`; the emission path (escaping, scheme-guarding) stays the core's.

## API reference

### `LaraFoundrySeo` (host wiring helper)

| Method | Returns | Purpose |
|--------|---------|---------|
| `sharedProps()` | `array<string, Closure>` | The `seo` Inertia prop (`SeoManager::toArray()`), lazily evaluated. Merge into `HandleInertiaRequests::share()`. |

### `SeoManager` (request singleton)

| Method | Returns | Purpose |
|--------|---------|---------|
| `title` / `description` / `canonical` / `robots` / `ogImage` / `ogType` | `self` | Fluent setters; each overrides the config default for the current request. |
| `noindex()` | `self` | Shorthand that sets `robots` to a `noindex` directive. |
| `renderHead()` | `string` | The server-rendered `<head>` HTML (behind `@larafoundrySeo`). Every value escaped, every URL scheme-guarded. |
| `toArray()` | `array` | The meta as a serialisable array, shipped as the `seo` shared prop for `<Seo>`. |

### `SitemapBuilder` (singleton)

| Method | Signature | Purpose |
|--------|-----------|---------|
| `addProvider` | `addProvider(SitemapProviderInterface $provider): self` | Register a provider that contributes URLs. |
| `build` | `build(): string` | Collect URLs from every provider, build each `<loc>` from the canonical base, and render XML-escaped sitemap XML. |

### `SitemapProviderInterface`

| Method | Returns | Purpose |
|--------|---------|---------|
| `getUrls` | `array` | The public, indexable URLs this provider contributes. |

The core provider is `LegalSitemapProvider` (published legal pages only).

### `OgImageResolver` (rebindable contract)

`Dmitryisaenko\LaraFoundry\Seo\Contracts\OgImageResolver`. The core binds
`ConfigOgImageResolver`, which returns the configured default image, resolving a
relative path through the `MediaStorage` contract. Rebind it to supply your own
(for example generated) images. No dynamic generation ships in the core.

## Security notes

- **Every meta value is escaped.** The server head escapes each title, description
  and attribute before emission, so a page value can never break out into markup.
- **Every URL is scheme-guarded.** Canonical, Open Graph and hreflang URLs are
  guarded to `http`/`https` before emission, so a `javascript:` or other non-http
  scheme can never reach a rendered tag.
- **The sitemap is XML-escaped.** Each `<loc>` is XML-escaped, so a URL cannot
  inject sitemap markup.
- **Canonical and sitemap URLs come from the configured base, not the request
  host.** Every `<loc>` and canonical is built from `larafoundry-seo.canonical.base_url`
  (falling back to `app.url`), never the incoming `Host` header. The review found
  that building from the request host let a forged `Host` header poison the cached
  sitemap; deriving from config closes it.
- **The public routes carry no auth and expose only public data.** `sitemap.xml` and
  `robots.txt` are web-only and unauthenticated by design; the core sitemap provider
  emits only published legal pages, so nothing private is listed.

## Host integration

Two steps are required for a host; the third is optional.

1. **Add the Blade directive to the head.** Put `@larafoundrySeo` inside the
   `<head>` of the host's `resources/views/app.blade.php`, so crawlers and social
   unfurlers get the server-rendered meta:

   ```blade
   <head>
       {{-- ... --}}
       @larafoundrySeo
   </head>
   ```

2. **Merge the shared prop.** Add the SEO prop to the host's
   `HandleInertiaRequests::share()`, so the client `<Seo>` component keeps meta
   current across SPA navigation:

   ```php
   use Dmitryisaenko\LaraFoundry\Seo\LaraFoundrySeo;

   public function share(Request $request): array
   {
       return [
           ...parent::share($request),
           ...LaraFoundrySeo::sharedProps(),
       ];
   }
   ```

3. **(Optional) Publish the config and set env.** Publish
   `larafoundry-seo-config` and set the `LARAFOUNDRY_SEO_*` env (notably the
   canonical base and the default OG image).

The `sitemap.xml` / `robots.txt` routes and the `<Seo>` component ship in the
package; a host adds nothing for them beyond the two steps above.

## Testing

The SEO suite lives in `tests/Feature/Seo/`. It covers the `SeoManager` fallbacks
and the escaped, scheme-guarded server head; the `noindex` on the sensitive Fortify
screens and the indexed title on published legal pages; the sitemap provider
registry and the published-only `LegalSitemapProvider`; the canonical/sitemap base
coming from config rather than the request host (the Host-header fix); and the
hreflang emission from the configured locales. Every module passes
`/security-review` + `/code-review` before its tag.

Run them with Pest:

```bash
composer test
```
