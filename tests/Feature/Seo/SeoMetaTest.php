<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\LaraFoundryAuth;
use Dmitryisaenko\LaraFoundry\Legal\Support\LegalPageRepository;
use Dmitryisaenko\LaraFoundry\Seo\Support\SeoManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Fortify\Contracts\ResetPasswordViewResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    config()->set('larafoundry.locale.available', ['en', 'uk']);
    config()->set('larafoundry.locale.default', 'en');
    config()->set('larafoundry-legal.pages', [
        'terms' => [
            'title' => ['en' => 'Terms', 'uk' => 'Умови'],
            'body_html' => ['en' => '<p>Default terms</p>', 'uk' => '<p>Типові умови</p>'],
        ],
    ]);
});

// --- renderHead output ------------------------------------------------------

it('renders escaped title, description, canonical, robots, OG and twitter tags', function () {
    config()->set('larafoundry-seo.og.default_image', 'https://cdn.test/og.png');
    config()->set('larafoundry-seo.twitter.site', '@kohana');
    config()->set('app.name', 'Kohana');

    $head = app(SeoManager::class)
        ->title('Welcome')
        ->description('A friendly place')
        ->renderHead();

    expect($head)->toContain('<title>Welcome</title>')
        ->and($head)->toContain('<meta name="description" content="A friendly place">')
        ->and($head)->toContain('<link rel="canonical"')
        ->and($head)->toContain('<meta name="robots" content="index,follow">')
        ->and($head)->toContain('property="og:title"')
        ->and($head)->toContain('property="og:type"')
        ->and($head)->toContain('property="og:image" content="https://cdn.test/og.png"')
        ->and($head)->toContain('property="og:site_name" content="Kohana"')
        ->and($head)->toContain('name="twitter:card"')
        ->and($head)->toContain('name="twitter:site" content="@kohana"')
        ->and($head)->toContain('hreflang="en"')
        ->and($head)->toContain('hreflang="x-default"');
});

it('escapes a malicious title so no raw script tag is emitted', function () {
    $head = app(SeoManager::class)
        ->title('"><script>alert(1)</script>')
        ->renderHead();

    expect($head)->not->toContain('<script>alert(1)</script>')
        ->and($head)->toContain('&lt;script&gt;')
        ->and($head)->toContain('&quot;');
});

it('drops a non-http og:image rather than emitting it', function () {
    $head = app(SeoManager::class)
        ->title('X')
        ->ogImage('javascript:alert(1)')
        ->renderHead();

    expect($head)->not->toContain('javascript:')
        ->and($head)->not->toContain('og:image');
});

// --- opt-in indexing --------------------------------------------------------

it('marks a published legal page index,follow with its real title', function () {
    app(LegalPageRepository::class)->save('terms', [
        'title' => ['en' => 'Live Terms', 'uk' => 'Умови'],
        'body_html' => ['en' => '<p>Read these terms carefully</p>', 'uk' => '<p>умови</p>'],
        'is_published' => true,
    ]);

    $this->withHeader('X-Inertia', 'true')
        ->get('/legal/terms')
        ->assertOk();

    $seo = app(SeoManager::class)->toArray();

    expect($seo['robots'])->toBe('index,follow')
        ->and($seo['title'])->toBe('Live Terms')
        ->and($seo['description'])->toContain('Read these terms');
});

it('marks the reset-password screen noindex,nofollow', function () {
    LaraFoundryAuth::registerFortifyViews();

    $request = Request::create('/reset-password/token123', 'GET');
    $request->headers->set('X-Inertia', 'true');

    app(ResetPasswordViewResponse::class)->toResponse($request);

    expect(app(SeoManager::class)->toArray()['robots'])->toBe('noindex,nofollow');
});
