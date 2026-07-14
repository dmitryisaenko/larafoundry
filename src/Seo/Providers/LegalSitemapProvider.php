<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Seo\Providers;

use Dmitryisaenko\LaraFoundry\Legal\Support\LegalPageRepository;
use Dmitryisaenko\LaraFoundry\Seo\Contracts\SitemapProviderInterface;
use Dmitryisaenko\LaraFoundry\Seo\Support\SeoManager;
use Dmitryisaenko\LaraFoundry\Seo\Support\Url;
use Illuminate\Support\Facades\Route;

/**
 * Contributes the core's PUBLISHED legal pages to the sitemap (phase 5.2).
 *
 * Reads the legal-page repository and emits one entry per published slug (an
 * unpublished or un-edited slug 404s publicly, so it is never listed). Each
 * entry carries hreflang alternates — one per available locale, all pointing at
 * the same canonical URL, matching the core's session-based locale switch (there
 * is no per-locale URL segment). Gated by the sitemap master switch.
 */
class LegalSitemapProvider implements SitemapProviderInterface
{
    public function __construct(
        private readonly LegalPageRepository $pages,
    ) {}

    public function entries(): iterable
    {
        // The public legal route must exist for a loc to be meaningful.
        if (! Route::has('larafoundry.legal.show')) {
            return;
        }

        $available = config('larafoundry.locale.available', ['en']);
        $available = is_array($available) ? array_values($available) : ['en'];

        foreach ($this->pages->all() as $page) {
            if (($page['is_published'] ?? false) !== true) {
                continue;
            }

            $loc = $this->loc((string) $page['slug']);

            $alternates = [];
            foreach ($available as $locale) {
                $alternates[(string) $locale] = $loc;
            }

            yield [
                'loc' => $loc,
                'lastmod' => null,
                'changefreq' => 'monthly',
                'priority' => 0.5,
                'alternates' => $alternates,
            ];
        }
    }

    /**
     * The absolute URL for a legal slug, built against the configured canonical
     * base (larafoundry-seo.canonical.base_url ?: app.url) rather than the request
     * host, so the cached sitemap cannot be steered by a spoofed Host header (and
     * so a loc matches {@see SeoManager}'s
     * canonical). Falls back to the request-derived absolute route only when no
     * base URL is configured.
     */
    private function loc(string $slug): string
    {
        $base = config('larafoundry-seo.canonical.base_url') ?: config('app.url');

        if (Url::isHttp(is_string($base) ? $base : null)) {
            return rtrim($base, '/').route('larafoundry.legal.show', $slug, false);
        }

        return route('larafoundry.legal.show', $slug);
    }

    public function supports(): bool
    {
        return (bool) config('larafoundry-seo.sitemap.enabled', true);
    }

    public function priority(): int
    {
        return 100;
    }
}
