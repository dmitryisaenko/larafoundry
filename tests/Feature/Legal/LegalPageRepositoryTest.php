<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Legal\Models\LegalPage;
use Dmitryisaenko\LaraFoundry\Legal\Support\LegalPageRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    // One override row caches under a single key; flush so each test starts clean.
    Cache::flush();

    config()->set('larafoundry.locale.available', ['en', 'uk']);
    config()->set('larafoundry.locale.default', 'en');
    config()->set('larafoundry-legal.pages', [
        'terms' => [
            'title' => ['en' => 'Terms', 'uk' => 'Умови'],
            'body_html' => ['en' => '<p>Default terms</p>', 'uk' => '<p>Типові умови</p>'],
        ],
    ]);

    $this->repo = app(LegalPageRepository::class);
});

it('resolves the registry default when there is no override', function () {
    $page = $this->repo->find('terms');

    expect($page['title']['en'])->toBe('Terms')
        ->and($page['is_published'])->toBeFalse()
        ->and($page['version'])->toBe(0);
});

it('returns null for an unregistered slug', function () {
    expect($this->repo->find('nope'))->toBeNull();
});

it('merges a stored override over the registry default per locale', function () {
    LegalPage::create([
        'slug' => 'terms',
        'title_translations' => ['en' => 'My Terms'], // override only EN title
        'body_html_translations' => ['en' => '<p>Custom</p>'],
        'version' => 2,
        'is_published' => true,
        'published_at' => now(),
    ]);

    $page = $this->repo->find('terms');

    // EN comes from the override, UK falls back to the registry default.
    expect($page['title']['en'])->toBe('My Terms')
        ->and($page['title']['uk'])->toBe('Умови')
        ->and($page['version'])->toBe(2);
});

it('is fail-closed: saving an unregistered slug throws', function () {
    expect(fn () => $this->repo->save('nope', ['title' => [], 'body_html' => []]))
        ->toThrow(RuntimeException::class);
});

it('purifies the html body on save', function () {
    $this->repo->save('terms', [
        'title' => ['en' => 'T', 'uk' => 'T'],
        'body_html' => ['en' => '<p>ok</p><script>alert(1)</script>', 'uk' => '<p>ok</p>'],
        'is_published' => true,
    ]);

    $row = LegalPage::where('slug', 'terms')->first();

    expect($row->body_html_translations['en'])
        ->toContain('<p>ok</p>')
        ->not->toContain('<script');
});

it('busts the cache on save so the next read sees the override', function () {
    // Prime the cache with the default.
    expect($this->repo->find('terms')['title']['en'])->toBe('Terms');

    $this->repo->save('terms', [
        'title' => ['en' => 'Changed', 'uk' => 'Changed'],
        'body_html' => ['en' => '<p>x</p>', 'uk' => '<p>x</p>'],
        'is_published' => true,
    ]);

    expect($this->repo->find('terms')['title']['en'])->toBe('Changed');
});

it('does not expose an unpublished page publicly', function () {
    $this->repo->save('terms', [
        'title' => ['en' => 'Draft', 'uk' => 'Draft'],
        'body_html' => ['en' => '<p>draft</p>', 'uk' => '<p>draft</p>'],
        'is_published' => false,
    ]);

    expect($this->repo->publicPage('terms', 'en'))->toBeNull();
});

it('exposes a published page publicly with a sanitized body', function () {
    $this->repo->save('terms', [
        'title' => ['en' => 'Live Terms', 'uk' => 'Умови'],
        'body_html' => ['en' => '<p>live<script>alert(1)</script></p>', 'uk' => '<p>умови</p>'],
        'is_published' => true,
    ]);

    $page = $this->repo->publicPage('terms', 'en');

    expect($page['title'])->toBe('Live Terms')
        ->and($page['body_html'])->toContain('live')
        ->and($page['body_html'])->not->toContain('<script');
});

it('returns null publicPage for an unregistered slug', function () {
    expect($this->repo->publicPage('nope', 'en'))->toBeNull();
});

it('reports the current version only for a published page', function () {
    expect($this->repo->currentVersion('terms'))->toBeNull();

    $this->repo->save('terms', [
        'title' => ['en' => 'T', 'uk' => 'T'],
        'body_html' => ['en' => '<p>x</p>', 'uk' => '<p>x</p>'],
        'is_published' => true,
    ]);

    // A published page always carries a version of at least 1.
    expect($this->repo->currentVersion('terms'))->toBe(1);
});

it('bumps the version only when asked', function () {
    $this->repo->save('terms', [
        'title' => ['en' => 'T', 'uk' => 'T'],
        'body_html' => ['en' => '<p>x</p>', 'uk' => '<p>x</p>'],
        'is_published' => true,
    ]);
    expect($this->repo->find('terms')['version'])->toBe(1);

    // Save again without bumping — version stays.
    $this->repo->save('terms', [
        'title' => ['en' => 'T2', 'uk' => 'T2'],
        'body_html' => ['en' => '<p>y</p>', 'uk' => '<p>y</p>'],
        'is_published' => true,
    ]);
    expect($this->repo->find('terms')['version'])->toBe(1);

    // Save with the bump flag — version increments.
    $this->repo->save('terms', [
        'title' => ['en' => 'T3', 'uk' => 'T3'],
        'body_html' => ['en' => '<p>z</p>', 'uk' => '<p>z</p>'],
        'is_published' => true,
        'bump_version' => true,
    ]);
    expect($this->repo->find('terms')['version'])->toBe(2);
});

it('lists every registered page with its published and customised flags', function () {
    $all = $this->repo->all();

    expect($all)->toHaveCount(1)
        ->and($all[0]['slug'])->toBe('terms')
        ->and($all[0]['customized'])->toBeFalse()
        ->and($all[0]['is_published'])->toBeFalse();
});

// --- import (v0.50.0) -------------------------------------------------------

it('imports a page with the version and date it has elsewhere, and shows it at once', function () {
    expect($this->repo->currentVersion('terms'))->toBeNull(); // caches the empty map

    $wrote = $this->repo->import('terms', [
        'title' => ['en' => 'Terms'],
        'body_html' => ['en' => '<p>ok</p><script>alert(1)</script>'],
        'version' => 3,
        'is_published' => true,
        'published_at' => '2026-09-20 17:15:14',
    ]);

    $page = LegalPage::query()->where('slug', 'terms')->first();

    expect($wrote)->toBeTrue()
        ->and($page->version)->toBe(3)
        ->and($page->published_at->toDateTimeString())->toBe('2026-09-20 17:15:14')
        ->and($page->body_html_translations['en'])->not->toContain('<script')
        ->and($this->repo->currentVersion('terms'))->toBe(3)
        ->and($this->repo->publicPage('terms', 'en')['published_at'])->toBe('2026-09-20');
});

it('keeps version 0 on import, which save would have raised', function () {
    $this->repo->import('terms', [
        'title' => ['en' => 'T'],
        'body_html' => ['en' => '<p>b</p>'],
        'version' => 0,
        'is_published' => true,
        'published_at' => now(),
    ]);

    expect($this->repo->currentVersion('terms'))->toBe(0);
});

it('leaves a page already stored alone on import', function () {
    $this->repo->save('terms', ['title' => ['en' => 'Mine'], 'body_html' => ['en' => '<p>mine</p>'], 'is_published' => true]);

    $wrote = $this->repo->import('terms', [
        'title' => ['en' => 'Other'],
        'body_html' => ['en' => '<p>other</p>'],
        'version' => 9,
        'is_published' => true,
        'published_at' => now(),
    ]);

    expect($wrote)->toBeFalse()
        ->and($this->repo->publicPage('terms', 'en')['title'])->toBe('Mine')
        ->and($this->repo->currentVersion('terms'))->toBe(1);
});

it('refuses to import an unregistered page', function () {
    $this->repo->import('nope', [
        'title' => [],
        'body_html' => [],
        'version' => 1,
        'is_published' => true,
        'published_at' => null,
    ]);
})->throws(RuntimeException::class);
