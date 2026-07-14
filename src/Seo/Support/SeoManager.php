<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Seo\Support;

use Dmitryisaenko\LaraFoundry\Seo\Contracts\OgImageResolver;

/**
 * The request-scoped meta holder + server-side head renderer (phase 5.2).
 *
 * A page (a controller, a Fortify view closure) sets its meta with fluent
 * setters; the SeoManager fills every unset value from config or a sensible
 * default. Two consumers read it back: {@see LaraFoundrySeo::sharedProps()}
 * ships {@see toArray()} as the `seo` Inertia prop (the client <Seo> component
 * re-emits tags on SPA navigation), and the `@larafoundrySeo` Blade directive
 * echoes {@see renderHead()} into the host's <head> (the crawler/social path).
 *
 * SECURITY: renderHead() escapes EVERY attribute value with e() (htmlspecialchars,
 * ENT_QUOTES), and only ever emits a URL — canonical, og:url, og:image,
 * hreflang — when it passes the http(s) scheme guard ({@see Url::isHttp()}), so a
 * `javascript:`/`data:` value can never reach an href. Bound as a singleton, so
 * the whole request shares one holder.
 */
class SeoManager
{
    protected ?string $title = null;

    protected ?string $description = null;

    protected ?string $canonical = null;

    protected ?string $robots = null;

    protected ?string $ogImage = null;

    protected ?string $ogType = null;

    public function title(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function description(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function canonical(?string $url): self
    {
        $this->canonical = $url;

        return $this;
    }

    public function robots(string $robots): self
    {
        $this->robots = $robots;

        return $this;
    }

    /**
     * Mark the current page as non-indexable (sensitive screens).
     */
    public function noindex(): self
    {
        $this->robots = 'noindex,nofollow';

        return $this;
    }

    public function ogImage(?string $url): self
    {
        $this->ogImage = $url;

        return $this;
    }

    public function ogType(string $type): self
    {
        $this->ogType = $type;

        return $this;
    }

    /**
     * Bulk-set from an associative array of any of the fluent fields.
     *
     * @param  array<string, mixed>  $values
     */
    public function set(array $values): self
    {
        foreach (['title', 'description', 'canonical', 'robots', 'ogImage', 'ogType'] as $key) {
            if (array_key_exists($key, $values)) {
                $this->{$key} = $values[$key] === null ? null : (string) $values[$key];
            }
        }

        return $this;
    }

    // --- Resolution (defaults applied) --------------------------------------

    public function resolvedTitle(): ?string
    {
        $title = $this->title
            ?? config('larafoundry-seo.defaults.title')
            ?? config('app.name');

        return $this->stringOrNull($title);
    }

    public function resolvedDescription(): ?string
    {
        return $this->stringOrNull(
            $this->description ?? config('larafoundry-seo.defaults.description')
        );
    }

    public function resolvedRobots(): string
    {
        return (string) ($this->robots ?? config('larafoundry-seo.defaults.robots', 'index,follow'));
    }

    public function resolvedOgType(): string
    {
        return (string) ($this->ogType ?? config('larafoundry-seo.og.type', 'website'));
    }

    public function resolvedSiteName(): ?string
    {
        return $this->stringOrNull(
            config('larafoundry-seo.og.site_name') ?? config('app.name')
        );
    }

    public function resolvedOgImage(): ?string
    {
        $image = $this->ogImage ?? app(OgImageResolver::class)->default();

        // Only an http(s) URL is a valid image reference; a stray relative path
        // or a non-http scheme is dropped rather than emitted.
        return Url::isHttp($image) ? $image : null;
    }

    /**
     * The canonical URL: an explicit value (resolved absolute) or the current
     * request URL, optionally re-hosted onto the configured base URL.
     */
    public function resolvedCanonical(): string
    {
        $base = $this->stringOrNull(config('larafoundry-seo.canonical.base_url'))
            ?? $this->stringOrNull(config('app.url'));

        if ($this->canonical !== null) {
            // An absolute http(s) URL is used as-is; a path is hung off the base.
            if (Url::isHttp($this->canonical)) {
                return $this->canonical;
            }

            return $base !== null
                ? rtrim($base, '/').'/'.ltrim($this->canonical, '/')
                : url($this->canonical);
        }

        // No explicit canonical: the current request URL. When a base URL is
        // configured it fixes the host (so a canonical host differs from the
        // request host if the app is reached under several hostnames).
        if ($base !== null) {
            $path = request()->path();

            return $path === '/' ? rtrim($base, '/').'/' : rtrim($base, '/').'/'.ltrim($path, '/');
        }

        return url()->current();
    }

    /**
     * The hreflang alternates: one per available locale plus x-default, all
     * pointing at the canonical URL (the core has no per-locale URL segment).
     * Empty when the feature is disabled.
     *
     * @return array<string, string>
     */
    public function hreflangs(): array
    {
        if (! config('larafoundry-seo.hreflang.enabled', true)) {
            return [];
        }

        $available = config('larafoundry.locale.available', ['en']);
        $available = is_array($available) ? array_values($available) : ['en'];

        $canonical = $this->resolvedCanonical();

        $alternates = [];
        foreach ($available as $locale) {
            $alternates[(string) $locale] = $canonical;
        }

        // x-default points at the canonical too (a safe, valid default target).
        $alternates['x-default'] = $canonical;

        return $alternates;
    }

    /**
     * The `seo` shared Inertia prop consumed by the client <Seo> component.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->resolvedTitle(),
            'description' => $this->resolvedDescription(),
            'canonical' => $this->resolvedCanonical(),
            'robots' => $this->resolvedRobots(),
            'og' => [
                'type' => $this->resolvedOgType(),
                'image' => $this->resolvedOgImage(),
                'site_name' => $this->resolvedSiteName(),
            ],
            'twitter' => [
                'card' => (string) config('larafoundry-seo.twitter.card', 'summary_large_image'),
                'site' => $this->stringOrNull(config('larafoundry-seo.twitter.site')),
            ],
            'hreflangs' => $this->hreflangs(),
        ];
    }

    /**
     * The server-rendered <head> tags (the crawler/social path). Every value is
     * escaped; every URL is scheme-guarded before it is emitted.
     */
    public function renderHead(): string
    {
        $data = $this->toArray();
        $lines = [];

        $title = $data['title'];
        if ($title !== null && $title !== '') {
            $lines[] = '<title>'.e($title).'</title>';
            $lines[] = $this->meta('og:title', $title, 'property');
            $lines[] = $this->meta('twitter:title', $title);
        }

        $description = $data['description'];
        if ($description !== null && $description !== '') {
            $lines[] = $this->meta('description', $description);
            $lines[] = $this->meta('og:description', $description, 'property');
            $lines[] = $this->meta('twitter:description', $description);
        }

        $canonical = $data['canonical'];
        if (Url::isHttp($canonical)) {
            $lines[] = '<link rel="canonical" href="'.e($canonical).'">';
            $lines[] = $this->meta('og:url', $canonical, 'property');
        }

        $lines[] = $this->meta('robots', $data['robots']);
        $lines[] = $this->meta('og:type', $data['og']['type'], 'property');

        $ogImage = $data['og']['image'];
        if (Url::isHttp($ogImage)) {
            $lines[] = $this->meta('og:image', $ogImage, 'property');
            $lines[] = $this->meta('twitter:image', $ogImage);
        }

        $siteName = $data['og']['site_name'];
        if ($siteName !== null && $siteName !== '') {
            $lines[] = $this->meta('og:site_name', $siteName, 'property');
        }

        $lines[] = $this->meta('twitter:card', $data['twitter']['card']);

        $twitterSite = $data['twitter']['site'];
        if ($twitterSite !== null && $twitterSite !== '') {
            $lines[] = $this->meta('twitter:site', $twitterSite);
        }

        foreach ($data['hreflangs'] as $locale => $url) {
            if (Url::isHttp($url)) {
                $lines[] = '<link rel="alternate" hreflang="'.e((string) $locale).'" href="'.e($url).'">';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * A single escaped <meta> tag. `$attr` is 'name' (default) or 'property'
     * (Open Graph tags use property=).
     */
    protected function meta(string $name, ?string $content, string $attr = 'name'): string
    {
        return '<meta '.$attr.'="'.e($name).'" content="'.e((string) $content).'">';
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
