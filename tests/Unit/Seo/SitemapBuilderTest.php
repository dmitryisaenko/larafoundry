<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Seo\Contracts\SitemapProviderInterface;
use Dmitryisaenko\LaraFoundry\Seo\Support\SitemapBuilder;

/**
 * The SitemapBuilder merges, orders and de-dups entries from its providers
 * (phase 5.2). Pure unit tests against fakes — no DB, no routes.
 */

// A provider returning a fixed set of entries, with a configurable priority and
// supports() flag.
function fakeSitemapProvider(array $entries, int $priority = 0, bool $supports = true): SitemapProviderInterface
{
    return new class($entries, $priority, $supports) implements SitemapProviderInterface
    {
        public function __construct(
            private array $entries,
            private int $prio,
            private bool $supports,
        ) {}

        public function entries(): iterable
        {
            return $this->entries;
        }

        public function supports(): bool
        {
            return $this->supports;
        }

        public function priority(): int
        {
            return $this->prio;
        }
    };
}

it('merges entries from every supporting provider', function () {
    $builder = (new SitemapBuilder)
        ->addProvider(fakeSitemapProvider([['loc' => 'https://a.test/one']]))
        ->addProvider(fakeSitemapProvider([['loc' => 'https://a.test/two']]));

    $locs = array_column($builder->build(), 'loc');

    expect($locs)->toBe(['https://a.test/one', 'https://a.test/two']);
});

it('orders providers by priority (lower first)', function () {
    $builder = (new SitemapBuilder)
        ->addProvider(fakeSitemapProvider([['loc' => 'https://a.test/late']], priority: 100))
        ->addProvider(fakeSitemapProvider([['loc' => 'https://a.test/early']], priority: 1));

    $locs = array_column($builder->build(), 'loc');

    expect($locs)->toBe(['https://a.test/early', 'https://a.test/late']);
});

it('de-dups entries by loc, keeping the first', function () {
    $builder = (new SitemapBuilder)
        ->addProvider(fakeSitemapProvider([['loc' => 'https://a.test/dup', 'priority' => 0.9]]))
        ->addProvider(fakeSitemapProvider([['loc' => 'https://a.test/dup', 'priority' => 0.1]]));

    $entries = $builder->build();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['priority'])->toBe(0.9);
});

it('excludes providers whose supports() is false', function () {
    $builder = (new SitemapBuilder)
        ->addProvider(fakeSitemapProvider([['loc' => 'https://a.test/on']], supports: true))
        ->addProvider(fakeSitemapProvider([['loc' => 'https://a.test/off']], supports: false));

    $locs = array_column($builder->build(), 'loc');

    expect($locs)->toBe(['https://a.test/on']);
});

it('skips entries with a missing or empty loc', function () {
    $builder = (new SitemapBuilder)
        ->addProvider(fakeSitemapProvider([
            ['loc' => 'https://a.test/good'],
            ['loc' => ''],
            ['changefreq' => 'daily'],
        ]));

    $locs = array_column($builder->build(), 'loc');

    expect($locs)->toBe(['https://a.test/good']);
});

it('memoises the build and flush() clears it', function () {
    $builder = (new SitemapBuilder)
        ->addProvider(fakeSitemapProvider([['loc' => 'https://a.test/one']]));

    expect($builder->build())->toHaveCount(1);

    $builder->flush();
    $builder->addProvider(fakeSitemapProvider([['loc' => 'https://a.test/two']]));

    expect($builder->build())->toHaveCount(2);
});
