<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Seo;

use Dmitryisaenko\LaraFoundry\Navigation\LaraFoundryNavigation;
use Dmitryisaenko\LaraFoundry\Seo\Support\SeoManager;
use Dmitryisaenko\LaraFoundry\Tenancy\LaraFoundryTenancy;

/**
 * One-call wiring helpers a host invokes for the SEO kit (phase 5.2).
 *
 * Parallel to {@see LaraFoundryNavigation}
 * and {@see LaraFoundryTenancy}: the host
 * merges {@see sharedProps()} into its HandleInertiaRequests::share, and every
 * page receives the resolved `seo` prop the client <Seo> component re-emits on
 * SPA navigation. The server-side path (crawlers/social) is the `@larafoundrySeo`
 * Blade directive the host echoes in its <head>.
 */
class LaraFoundrySeo
{
    /**
     * Inertia shared props: the resolved SEO meta for the current request.
     *
     * Lazily evaluated — the closure runs only when a page serialises it.
     *
     * @return array<string, \Closure>
     */
    public static function sharedProps(): array
    {
        return [
            'seo' => fn () => app(SeoManager::class)->toArray(),
        ];
    }
}
