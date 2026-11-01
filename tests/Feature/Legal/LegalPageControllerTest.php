<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Legal\Models\LegalPage;
use Dmitryisaenko\LaraFoundry\Legal\Support\LegalPageRepository;
use Dmitryisaenko\LaraFoundry\Navigation\Providers\AdminMenuProvider;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    // Operator console without the OTP step-up, the flag-only super-admin model.
    config()->set('larafoundry.security.super_admin.require_otp', false);
    config()->set('larafoundry.security.super_admin.email', null);

    config()->set('larafoundry.locale.available', ['en', 'uk']);
    config()->set('larafoundry.locale.default', 'en');
    config()->set('larafoundry-legal.pages', [
        'terms' => [
            'title' => ['en' => 'Terms', 'uk' => 'Умови'],
            'body_html' => ['en' => '<p>Default terms</p>', 'uk' => '<p>Типові умови</p>'],
        ],
    ]);
});

function legalUser(string $email = 'u@x.test', bool $admin = false): User
{
    return User::create([
        'name' => 'U',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
        'is_admin' => $admin,
    ]);
}

/**
 * @return array<string, mixed>
 */
function validLegalPayload(): array
{
    return [
        'title' => ['en' => 'Terms of Service', 'uk' => 'Умови використання'],
        'body_html' => ['en' => '<p>Our terms</p>', 'uk' => '<p>Наші умови</p>'],
        'is_published' => true,
        'bump_version' => false,
    ];
}

// --- Admin: page rendering --------------------------------------------------

it('renders the legal pages list for a super-admin', function () {
    $this->actingAs(legalUser('boss@x.test', admin: true))
        ->withHeader('X-Inertia', 'true')
        ->get('/admin/legal-pages')
        ->assertOk()
        ->assertJsonPath('component', 'Admin/Legal/Index');
});

it('forbids a non-admin from the legal pages list', function () {
    $this->actingAs(legalUser())
        ->get('/admin/legal-pages')
        ->assertForbidden();
});

it('renders the editor for a registered page', function () {
    $this->actingAs(legalUser('boss@x.test', admin: true))
        ->withHeader('X-Inertia', 'true')
        ->get('/admin/legal-pages/terms/edit')
        ->assertOk()
        ->assertJsonPath('component', 'Admin/Legal/Edit')
        ->assertJsonPath('props.page.slug', 'terms');
});

it('404s the editor for an unregistered page', function () {
    $this->actingAs(legalUser('boss@x.test', admin: true))
        ->get('/admin/legal-pages/nope/edit')
        ->assertNotFound();
});

// --- Admin: update ----------------------------------------------------------

it('lets a super-admin save a page', function () {
    $this->actingAs(legalUser('boss@x.test', admin: true))
        ->put('/admin/legal-pages/terms', validLegalPayload())
        ->assertRedirect();

    expect(LegalPage::where('slug', 'terms')->where('is_published', true)->exists())->toBeTrue();
});

it('purifies a script out of the html body on save', function () {
    $payload = validLegalPayload();
    $payload['body_html']['en'] = '<p>Terms</p><script>alert(1)</script>';

    $this->actingAs(legalUser('boss@x.test', admin: true))
        ->put('/admin/legal-pages/terms', $payload)
        ->assertRedirect();

    expect(LegalPage::where('slug', 'terms')->first()->body_html_translations['en'])
        ->not->toContain('<script');
});

it('forbids a non-admin from saving a page', function () {
    $this->actingAs(legalUser())
        ->put('/admin/legal-pages/terms', validLegalPayload())
        ->assertForbidden();
});

// --- Admin: preview ---------------------------------------------------------

it('returns a server-sanitized preview', function () {
    $this->actingAs(legalUser('boss@x.test', admin: true))
        ->postJson('/admin/legal-pages/terms/preview', [
            'body_html' => '<p>Terms</p><script>alert(1)</script>',
        ])
        ->assertOk()
        ->assertJson(fn ($json) => $json->where('html', fn ($html) => str_contains($html, '<p>Terms</p>') && ! str_contains($html, '<script'))->etc());
});

it('forbids a non-admin from previewing', function () {
    $this->actingAs(legalUser())
        ->postJson('/admin/legal-pages/terms/preview', ['body_html' => '<p>x</p>'])
        ->assertForbidden();
});

// --- Public page ------------------------------------------------------------

it('shows a published legal page to a guest', function () {
    app(LegalPageRepository::class)->save('terms', [
        'title' => ['en' => 'Live Terms', 'uk' => 'Умови'],
        'body_html' => ['en' => '<p>live terms</p>', 'uk' => '<p>умови</p>'],
        'is_published' => true,
    ]);

    $this->withHeader('X-Inertia', 'true')
        ->get('/legal/terms')
        ->assertOk()
        ->assertJsonPath('component', 'Legal/Show')
        ->assertJsonPath('props.page.title', 'Live Terms');
});

it('404s a legal page that is not published', function () {
    app(LegalPageRepository::class)->save('terms', [
        'title' => ['en' => 'Draft', 'uk' => 'Draft'],
        'body_html' => ['en' => '<p>draft</p>', 'uk' => '<p>draft</p>'],
        'is_published' => false,
    ]);

    $this->get('/legal/terms')->assertNotFound();
});

it('404s an unregistered public legal slug', function () {
    $this->get('/legal/nope')->assertNotFound();
});

// --- Public page: the language a link asks for (v0.50.0) --------------------

it('shows the language a link asks for, for this page only', function () {
    app(LegalPageRepository::class)->save('terms', validLegalPayload());

    $this->withHeader('X-Inertia', 'true')
        ->get('/legal/terms?lang=uk')
        ->assertOk()
        ->assertJsonPath('props.page.title', 'Умови використання')
        ->assertJsonPath('props.page.body_locale', null);

    expect(app()->getLocale())->not->toBe('uk');
});

it('ignores a language the app does not offer, and one that is not a string', function () {
    // A German text exists, so only the check against the offered list can refuse it.
    $payload = validLegalPayload();
    $payload['title']['de'] = 'Bedingungen';
    app(LegalPageRepository::class)->save('terms', $payload);

    foreach (['/legal/terms?lang=de', '/legal/terms?lang[]=uk'] as $url) {
        $this->withHeader('X-Inertia', 'true')
            ->get($url)
            ->assertOk()
            ->assertJsonPath('props.page.title', 'Terms of Service');
    }
});

it('says which language it substituted when the page lacks the asked one', function () {
    // The registry's placeholder would fill the gap otherwise.
    config()->set('larafoundry-legal.pages.terms.body_html', []);

    app(LegalPageRepository::class)->save('terms', [
        'title' => ['en' => 'Terms'],
        'body_html' => ['en' => '<p>english only</p>'],
        'is_published' => true,
    ]);

    $this->withHeader('X-Inertia', 'true')
        ->get('/legal/terms?lang=uk')
        ->assertJsonPath('props.page.body_html', '<p>english only</p>')
        ->assertJsonPath('props.page.body_locale', 'en');
});

// --- Public page: a host's own template (v0.50.0) ---------------------------

it('shows the page with a host template, as plain html a reader needs no scripts for', function () {
    View::addLocation(__DIR__.'/../../Fixtures/views');
    config()->set('larafoundry-legal.public.view', 'legal-page');
    app(LegalPageRepository::class)->save('terms', validLegalPayload());

    $this->get('/legal/terms?lang=uk')
        ->assertOk()
        ->assertSee('<html lang="uk">', false)
        ->assertSee('<h1>Умови використання</h1>', false)
        ->assertSee('<div><p>Наші умови</p></div>', false)
        ->assertSee(now()->toDateString().'|', false)
        ->assertDontSee('data-page', false);
});

it('still 404s an unpublished page with a host template', function () {
    View::addLocation(__DIR__.'/../../Fixtures/views');
    config()->set('larafoundry-legal.public.view', 'legal-page');

    $this->get('/legal/terms')->assertNotFound();
});

// --- Admin: the editor can be switched off (v0.50.0) ------------------------

it('lists the legal pages in the admin menu only while the editor is on', function () {
    $menu = fn () => collect(app(AdminMenuProvider::class)->getMenuItems('admin'))
        ->map(fn ($item) => $item->labelKey)
        ->all();

    expect($menu())->toContain('Legal pages');

    config()->set('larafoundry-legal.admin.enabled', false);

    expect($menu())->not->toContain('Legal pages');
});

it('does not register the editor while it is switched off', function () {
    config()->set('larafoundry-legal.admin.enabled', false);

    $router = new Router(app('events'), app());
    Route::swap($router);

    require __DIR__.'/../../../routes/admin.php';
    $router->getRoutes()->refreshNameLookups();

    expect($router->getRoutes()->getByName('admin.legal-pages.index'))->toBeNull()
        ->and($router->getRoutes()->getByName('admin.legal-pages.update'))->toBeNull()
        ->and($router->getRoutes()->getByName('admin.help-articles.index'))->not->toBeNull();
});
