<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Seo\Contracts;

use Dmitryisaenko\LaraFoundry\Navigation\Contracts\MenuProviderInterface;
use Dmitryisaenko\LaraFoundry\Seo\Support\SitemapBuilder;

/**
 * Contributes URL entries to the sitemap (phase 5.2).
 *
 * The exact mirror of {@see MenuProviderInterface}:
 * the core ships providers for its own public surfaces (published legal pages);
 * a host registers its own providers to grow the sitemap without editing the
 * core — the same additive-merge idiom used for menus and permissions. A
 * provider just declares its entries; the {@see SitemapBuilder}
 * de-dups and orders them, and the controller escapes every value into XML.
 */
interface SitemapProviderInterface
{
    /**
     * The URL entries this provider contributes.
     *
     * Each entry is an array:
     *   'loc'        => string   absolute URL (required; non-http(s) is skipped)
     *   'lastmod'    => ?string  ISO 8601 timestamp
     *   'changefreq' => ?string  always|hourly|daily|weekly|monthly|yearly|never
     *   'priority'   => ?float   0.0-1.0
     *   'alternates' => ?array<string, string>  locale => URL hreflang alternates
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function entries(): iterable;

    /**
     * Whether this provider contributes anything (e.g. gated by config).
     */
    public function supports(): bool;

    /**
     * Provider ordering across the merged set (lower runs first).
     */
    public function priority(): int;
}
