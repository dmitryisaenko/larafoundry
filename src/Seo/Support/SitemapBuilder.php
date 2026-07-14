<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Seo\Support;

use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuBuilder;
use Dmitryisaenko\LaraFoundry\Seo\Contracts\SitemapProviderInterface;

/**
 * Merges sitemap entries from every supporting provider (phase 5.2).
 *
 * The exact mirror of {@see MenuBuilder}:
 * providers are kept ordered by their priority, `build()` collects entries from
 * every provider whose supports() is true and de-dups by `loc`, and the result
 * is memoised for the request. Registered as a singleton seeded with the core's
 * own providers; a host adds its own by resolving the builder and calling
 * addProvider — the additive idiom used across the core.
 */
class SitemapBuilder
{
    /**
     * @var array<int, SitemapProviderInterface>
     */
    protected array $providers = [];

    /**
     * Per-request memo of the built entries.
     *
     * @var array<int, array<string, mixed>>|null
     */
    protected ?array $cache = null;

    /**
     * Register a provider, keeping the set ordered by priority (lower first).
     */
    public function addProvider(SitemapProviderInterface $provider): self
    {
        $this->providers[] = $provider;

        usort($this->providers, static fn (SitemapProviderInterface $a, SitemapProviderInterface $b) => $a->priority() <=> $b->priority());

        $this->cache = null;

        return $this;
    }

    /**
     * Collect → de-dup → memoise the entries from every supporting provider.
     *
     * @return array<int, array<string, mixed>>
     */
    public function build(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $entries = [];
        $seen = [];

        foreach ($this->providers as $provider) {
            if (! $provider->supports()) {
                continue;
            }

            foreach ($provider->entries() as $entry) {
                $loc = $entry['loc'] ?? null;

                if (! is_string($loc) || $loc === '' || isset($seen[$loc])) {
                    continue;
                }

                $seen[$loc] = true;
                $entries[] = $entry;
            }
        }

        return $this->cache = $entries;
    }

    /**
     * Forget the memoised entries (tests, or after a context change).
     */
    public function flush(): void
    {
        $this->cache = null;
    }
}
