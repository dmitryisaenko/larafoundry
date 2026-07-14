<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Legal\Support\LegalPageRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    config()->set('larafoundry.locale.available', ['en', 'uk']);
    config()->set('larafoundry.locale.default', 'en');
    config()->set('larafoundry-seo.sitemap.enabled', true);
    config()->set('larafoundry-seo.robots.enabled', true);
    config()->set('larafoundry-legal.pages', [
        'terms' => [
            'title' => ['en' => 'Terms', 'uk' => 'Умови'],
            'body_html' => ['en' => '<p>Default terms</p>', 'uk' => '<p>Типові умови</p>'],
        ],
        'privacy' => [
            'title' => ['en' => 'Privacy', 'uk' => 'Приватність'],
            'body_html' => ['en' => '<p>Default privacy</p>', 'uk' => '<p>Типова приватність</p>'],
        ],
    ]);
});

function publishLegal(string $slug): void
{
    app(LegalPageRepository::class)->save($slug, [
        'title' => ['en' => ucfirst($slug), 'uk' => ucfirst($slug)],
        'body_html' => ['en' => "<p>{$slug}</p>", 'uk' => "<p>{$slug}</p>"],
        'is_published' => true,
    ]);
}

// --- sitemap.xml ------------------------------------------------------------

it('serves a well-formed xml sitemap', function () {
    publishLegal('terms');

    $response = $this->get('/sitemap.xml');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/xml');

    // Parses as XML (well-formed).
    $xml = simplexml_load_string($response->getContent());
    expect($xml)->not->toBeFalse();
});

it('lists only published legal slugs', function () {
    publishLegal('terms');
    // 'privacy' is registered but never published.

    $body = $this->get('/sitemap.xml')->getContent();

    expect($body)->toContain(route('larafoundry.legal.show', 'terms'))
        ->not->toContain(route('larafoundry.legal.show', 'privacy'));
});

it('builds sitemap locs against the configured canonical base, not the request host', function () {
    config()->set('larafoundry-seo.canonical.base_url', 'https://canonical.test');
    publishLegal('terms');

    // A spoofed Host must not steer the cached sitemap (SEO-poisoning guard).
    $body = $this->get('/sitemap.xml', ['Host' => 'spoofed.test'])->getContent();

    expect($body)->toContain('https://canonical.test/legal/terms')
        ->and($body)->not->toContain('spoofed.test');
});

it('emits hreflang alternates for every available locale', function () {
    publishLegal('terms');

    $body = $this->get('/sitemap.xml')->getContent();

    expect($body)->toContain('hreflang="en"')
        ->and($body)->toContain('hreflang="uk"')
        ->and($body)->toContain('<xhtml:link');
});

it('404s the sitemap when disabled', function () {
    config()->set('larafoundry-seo.sitemap.enabled', false);

    $this->get('/sitemap.xml')->assertNotFound();
});

// --- robots.txt -------------------------------------------------------------

it('serves a plain-text robots.txt pointing at the sitemap', function () {
    $response = $this->get('/robots.txt');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/plain');

    $body = $response->getContent();
    expect($body)->toContain('User-agent: *')
        ->and($body)->toContain('Sitemap: '.route('larafoundry.sitemap'));
});

it('404s robots.txt when disabled', function () {
    config()->set('larafoundry-seo.robots.enabled', false);

    $this->get('/robots.txt')->assertNotFound();
});
