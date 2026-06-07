<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Legal\Support\LegalPageRepository;
use Dmitryisaenko\LaraFoundry\Settings\Facades\Settings;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    config()->set('larafoundry.security.super_admin.require_otp', false);
    config()->set('larafoundry.security.super_admin.email', null);
    config()->set('larafoundry.locale.available', ['en', 'uk']);
    config()->set('larafoundry.locale.default', 'en');
    config()->set('larafoundry-legal.terms.enforce', true);
    config()->set('larafoundry-legal.terms.slug', 'terms');
    config()->set('larafoundry-legal.pages', [
        'terms' => [
            'title' => ['en' => 'Terms', 'uk' => 'Умови'],
            'body_html' => ['en' => '<p>Default terms</p>', 'uk' => '<p>Типові умови</p>'],
        ],
    ]);
});

function consentUser(string $email = 'u@x.test', bool $admin = false): User
{
    return User::create([
        'name' => 'U',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
        'is_admin' => $admin,
    ]);
}

/** Publish the terms page at version 1 (the published floor). */
function publishTerms(): void
{
    app(LegalPageRepository::class)->save('terms', [
        'title' => ['en' => 'Terms', 'uk' => 'Умови'],
        'body_html' => ['en' => '<p>Live terms</p>', 'uk' => '<p>Умови</p>'],
        'is_published' => true,
    ]);
    Cache::flush();
}

// --- Cookie consent ---------------------------------------------------------

it('lets a guest record a cookie-consent decision', function () {
    $this->post('/consent/cookies', ['decision' => 'accept'])
        ->assertRedirect()
        ->assertCookie('larafoundry_cookie_consent');
});

it('rejects an invalid cookie-consent decision', function () {
    $this->from('/')
        ->post('/consent/cookies', ['decision' => 'maybe'])
        ->assertSessionHasErrors('decision');
});

it('writes the cookie_consent setting for an authenticated user who accepts', function () {
    $user = consentUser();

    $this->actingAs($user)->post('/consent/cookies', ['decision' => 'accept'])->assertRedirect();

    expect(Settings::get('cookie_consent', $user->getKey()))->toBeTrue()
        ->and(Settings::has('cookie_consent', $user->getKey()))->toBeTrue();
});

it('writes a false cookie_consent setting for an authenticated user who picks necessary only', function () {
    $user = consentUser();

    $this->actingAs($user)->post('/consent/cookies', ['decision' => 'necessary'])->assertRedirect();

    expect(Settings::get('cookie_consent', $user->getKey()))->toBeFalse()
        ->and(Settings::has('cookie_consent', $user->getKey()))->toBeTrue();
});

it('adopts a guest cookie choice into settings on first login', function () {
    $user = consentUser();
    request()->cookies->set('larafoundry_cookie_consent', 'accept');

    event(new Login('web', $user, false));

    expect(Settings::get('cookie_consent', $user->getKey()))->toBeTrue();
});

it('adopts a necessary-only guest cookie choice on first login', function () {
    $user = consentUser();
    request()->cookies->set('larafoundry_cookie_consent', 'necessary');

    event(new Login('web', $user, false));

    expect(Settings::get('cookie_consent', $user->getKey()))->toBeFalse()
        ->and(Settings::has('cookie_consent', $user->getKey()))->toBeTrue();
});

it('does not override an existing cookie decision on login', function () {
    $user = consentUser();
    Settings::set('cookie_consent', false, $user->getKey());
    request()->cookies->set('larafoundry_cookie_consent', 'accept');

    event(new Login('web', $user, false));

    expect(Settings::get('cookie_consent', $user->getKey()))->toBeFalse();
});

// --- Terms acceptance screen + recording ------------------------------------

it('renders the accept screen for a user with a stale terms version', function () {
    publishTerms();

    $this->actingAs(consentUser())
        ->withHeader('X-Inertia', 'true')
        ->get('/terms/accept')
        ->assertOk()
        ->assertJsonPath('component', 'Legal/Accept')
        ->assertJsonPath('props.version', 1);
});

it('redirects away from the accept screen when terms are already accepted', function () {
    publishTerms();
    $user = consentUser();
    Settings::set('terms_accepted_version', '1', $user->getKey());

    $this->actingAs($user)->get('/terms/accept')->assertRedirect();
});

it('records the version and timestamp when a user accepts the terms', function () {
    publishTerms();
    $user = consentUser();

    $this->actingAs($user)->post('/terms/accept')->assertRedirect();

    expect(Settings::get('terms_accepted_version', $user->getKey()))->toBe('1')
        ->and(Settings::get('terms_accepted_at', $user->getKey()))->not->toBeNull();
});

// --- Re-accept gate (larafoundry.terms) -------------------------------------

function gateRoute(): void
{
    Route::middleware(['web', 'larafoundry.terms'])->get('/__terms-gate', fn () => 'ok')->name('terms.gate.test');
}

it('redirects a user with a stale terms version through the gate', function () {
    publishTerms();
    gateRoute();

    $this->actingAs(consentUser())
        ->get('/__terms-gate')
        ->assertRedirect(route('larafoundry.consent.terms.show'));
});

it('lets a user through the gate once terms are accepted', function () {
    publishTerms();
    gateRoute();
    $user = consentUser();
    Settings::set('terms_accepted_version', '1', $user->getKey());

    $this->actingAs($user)->get('/__terms-gate')->assertOk()->assertSee('ok');
});

it('is fail-open when no terms page is published', function () {
    gateRoute();

    // Terms registered but never published → currentVersion null → no enforcement.
    $this->actingAs(consentUser())->get('/__terms-gate')->assertOk();
});

it('does not enforce the gate when enforcement is switched off', function () {
    publishTerms();
    config()->set('larafoundry-legal.terms.enforce', false);
    gateRoute();

    $this->actingAs(consentUser())->get('/__terms-gate')->assertOk();
});

it('exempts the super-admin from the terms gate', function () {
    publishTerms();
    gateRoute();

    $this->actingAs(consentUser('boss@x.test', admin: true))->get('/__terms-gate')->assertOk();
});

it('lets guests through the gate', function () {
    publishTerms();
    gateRoute();

    $this->get('/__terms-gate')->assertOk();
});

it('hard-fails a json client with 403 instead of redirecting', function () {
    publishTerms();
    gateRoute();

    $this->actingAs(consentUser())->getJson('/__terms-gate')->assertForbidden();
});

it('lets a stale user reach an excluded path prefix through the gate (no redirect loop)', function () {
    publishTerms();
    config()->set('larafoundry-legal.terms.except_prefixes', ['public-docs']);
    Route::middleware(['web', 'larafoundry.terms'])->get('/public-docs/privacy', fn () => 'ok');

    $this->actingAs(consentUser())->get('/public-docs/privacy')->assertOk();
});

it('lets a stale user reach an excluded route by name through the gate', function () {
    publishTerms();
    Route::middleware(['web', 'larafoundry.terms'])->get('/__bye', fn () => 'ok')->name('logout');

    $this->actingAs(consentUser())->get('/__bye')->assertOk();
});

it('still gates a host path that merely shares a prefix with an excluded one', function () {
    publishTerms();
    // `public-docs-internal` must NOT be whitelisted by the `public-docs` prefix
    // (the match is segment-aware).
    config()->set('larafoundry-legal.terms.except_prefixes', ['public-docs']);
    Route::middleware(['web', 'larafoundry.terms'])->get('/public-docs-internal', fn () => 'ok');

    $this->actingAs(consentUser())
        ->get('/public-docs-internal')
        ->assertRedirect(route('larafoundry.consent.terms.show'));
});
