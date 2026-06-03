<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('larafoundry.locale.available', ['en', 'uk']);
    config()->set('larafoundry.locale.default', 'en');
    config()->set('larafoundry.locale.cookie', 'locale');
});

it('stores the choice in session and cookie for a guest', function () {
    $response = $this->from('/somewhere')
        ->post(route('larafoundry.language.switch'), ['locale' => 'uk']);

    $response->assertRedirect('/somewhere');
    $response->assertSessionHas('locale', 'uk');
    $response->assertCookie('locale', 'uk');
});

it('persists the choice on the authenticated user, not only the session', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->post(route('larafoundry.language.switch'), ['locale' => 'uk']);

    $response->assertRedirect('/dashboard');
    $response->assertSessionHas('locale', 'uk');

    expect($user->fresh()->preferredLocale())->toBe('uk');
});

it('keeps the authenticated choice across the next request (no bounce-back)', function () {
    // The DB preference is authoritative in SetLocale; persisting it here is what
    // prevents the stale value from overriding the choice on the very next visit.
    $user = User::create([
        'name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass', 'locale' => 'en',
    ]);

    $this->actingAs($user)
        ->post(route('larafoundry.language.switch'), ['locale' => 'uk']);

    expect($user->fresh()->preferredLocale())->toBe('uk');
});

it('rejects a locale outside the whitelist', function () {
    $response = $this->from('/somewhere')
        ->post(route('larafoundry.language.switch'), ['locale' => 'ua']); // legacy junk

    $response->assertSessionHasErrors('locale');
    $response->assertSessionMissing('locale');
});

it('rejects a non-string locale', function () {
    $response = $this->post(route('larafoundry.language.switch'), ['locale' => ['en']]);

    $response->assertSessionHasErrors('locale');
});

it('requires a locale', function () {
    $response = $this->post(route('larafoundry.language.switch'), []);

    $response->assertSessionHasErrors('locale');
});

it('does not redirect to an external url even with a forged referer', function () {
    // URL::previous() falls back to the (attacker-controlled) Referer header when
    // the session has no stored previous URL. The protection is LanguageController's
    // safeReturnUrl() host check, which rejects an off-host target — NOT back()
    // ignoring the Referer. Do not drop that host check.
    $response = $this->withHeaders(['referer' => 'https://evil.example/phish'])
        ->post(route('larafoundry.language.switch'), ['locale' => 'uk']);

    $location = $response->headers->get('Location');

    expect($location)->not->toContain('evil.example');
});
