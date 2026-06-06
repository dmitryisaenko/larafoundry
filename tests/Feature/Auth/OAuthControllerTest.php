<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('larafoundry.auth.oauth.enabled', true);
    config()->set('larafoundry.auth.oauth.providers', ['google']);
    config()->set('larafoundry.auth.oauth.link_existing', false);
});

/** Build a Socialite user (the verified profile the provider returns). */
function socialUser(string $id, string $email, ?string $name = 'Social Name'): SocialiteUser
{
    $user = (new SocialiteUser)->setRaw([])->map([
        'id' => $id,
        'nickname' => 'nick',
        'name' => $name,
        'email' => $email,
        'avatar' => 'https://avatar.test/a.png',
    ]);
    $user->token = 'access-token';
    $user->refreshToken = 'refresh-token';

    return $user;
}

/** Point Socialite::driver('google')->user() at the given profile. */
function fakeSocialiteReturns(SocialiteUser $social): void
{
    $driver = Mockery::mock();
    $driver->shouldReceive('user')->andReturn($social);
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
}

it('creates a brand-new, email-verified, authenticated user (case a)', function () {
    fakeSocialiteReturns(socialUser('gid-1', 'new@x.test'));

    $response = $this->get(route('larafoundry.oauth.callback', ['provider' => 'google']));

    $response->assertRedirect();

    $user = User::where('email', 'new@x.test')->first();
    expect($user)->not->toBeNull()
        ->and($user->provider_name)->toBe('google')
        ->and($user->provider_id)->toBe('gid-1')
        ->and($user->email_verified_at)->not->toBeNull();

    expect(Auth::check())->toBeTrue()
        ->and(Auth::id())->toBe($user->id);
});

it('logs the same linked user back in without creating a duplicate (case b)', function () {
    $existing = User::create([
        'name' => 'Existing',
        'email' => 'linked@x.test',
        'password' => null,
        'provider_name' => 'google',
        'provider_id' => 'gid-2',
    ]);

    fakeSocialiteReturns(socialUser('gid-2', 'linked@x.test'));

    $this->get(route('larafoundry.oauth.callback', ['provider' => 'google']))
        ->assertRedirect();

    expect(User::count())->toBe(1);
    expect(Auth::id())->toBe($existing->id);
});

it('REFUSES to bind an OAuth identity to an existing local account by email (case c — account takeover guard)', function () {
    // Victim owns a local password account. Attacker controls a provider
    // account asserting the victim's email but a brand-new provider id.
    $victim = User::create([
        'name' => 'Victim',
        'email' => 'victim@x.test',
        'password' => 'victims-own-password',
    ]);

    config()->set('larafoundry.auth.oauth.link_existing', false);

    fakeSocialiteReturns(socialUser('attacker-gid', 'victim@x.test'));

    $response = $this->get(route('larafoundry.oauth.callback', ['provider' => 'google']));

    // The takeover MUST be refused: redirect with an error, no login.
    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect(Auth::check())->toBeFalse();

    // The victim's account must be untouched — no provider linkage written.
    $victim->refresh();
    expect($victim->provider_name)->toBeNull()
        ->and($victim->provider_id)->toBeNull()
        ->and($victim->password)->not->toBeNull();

    // And no second account got created.
    expect(User::count())->toBe(1);

    // The remember flag is pulled at the very start of callback() on every path,
    // so a refused attempt must not leak it into the next login attempt.
    expect(session()->has('oauth_remember'))->toBeFalse();
});

it('links the provider to the existing account when link_existing is true (case d)', function () {
    $existing = User::create([
        'name' => 'Owner',
        'email' => 'owner@x.test',
        'password' => 'owners-password',
    ]);

    config()->set('larafoundry.auth.oauth.link_existing', true);

    fakeSocialiteReturns(socialUser('gid-link', 'owner@x.test'));

    $this->get(route('larafoundry.oauth.callback', ['provider' => 'google']))
        ->assertRedirect();

    $existing->refresh();
    expect($existing->provider_name)->toBe('google')
        ->and($existing->provider_id)->toBe('gid-link');

    expect(Auth::id())->toBe($existing->id);
    expect(User::count())->toBe(1);
});

it('REFUSES OAuth that would claim the reserved super-admin email on a new account', function () {
    config()->set('larafoundry.security.super_admin.email', 'boss@x.test');

    fakeSocialiteReturns(socialUser('attacker-gid', 'boss@x.test'));

    $response = $this->get(route('larafoundry.oauth.callback', ['provider' => 'google']));

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect(Auth::check())->toBeFalse()
        ->and(User::where('email', 'boss@x.test')->exists())->toBeFalse();
});

it('REFUSES OAuth linking to the reserved super-admin email even with link_existing on', function () {
    config()->set('larafoundry.security.super_admin.email', 'boss@x.test');
    config()->set('larafoundry.auth.oauth.link_existing', true);

    // The real super-admin account exists locally (provisioned out-of-band).
    $admin = User::create([
        'name' => 'Boss',
        'email' => 'boss@x.test',
        'password' => 'admins-own-password',
        'is_admin' => true,
    ]);

    // Attacker controls a provider account asserting the admin's email (any case).
    fakeSocialiteReturns(socialUser('attacker-gid', 'BOSS@X.TEST'));

    $response = $this->get(route('larafoundry.oauth.callback', ['provider' => 'google']));

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect(Auth::check())->toBeFalse();

    // The operator account must not get a provider linkage written.
    $admin->refresh();
    expect($admin->provider_name)->toBeNull()
        ->and($admin->provider_id)->toBeNull();
});

it('still lets an already-linked operator sign in through OAuth', function () {
    config()->set('larafoundry.security.super_admin.email', 'boss@x.test');

    // The operator linked the provider out-of-band (branch 1 — existing link).
    $admin = User::create([
        'name' => 'Boss',
        'email' => 'boss@x.test',
        'password' => 'admins-own-password',
        'is_admin' => true,
        'provider_name' => 'google',
        'provider_id' => 'admin-gid',
    ]);

    fakeSocialiteReturns(socialUser('admin-gid', 'boss@x.test'));

    $this->get(route('larafoundry.oauth.callback', ['provider' => 'google']))
        ->assertRedirect();

    expect(Auth::id())->toBe($admin->id)
        ->and(User::count())->toBe(1);
});

it('refuses a provider that is not in the allow-list (case e)', function () {
    config()->set('larafoundry.auth.oauth.providers', ['github']);

    $response = $this->get(route('larafoundry.oauth.callback', ['provider' => 'google']));

    $response->assertRedirect('/');
    $response->assertSessionHas('error');
    expect(Auth::check())->toBeFalse();
    expect(User::count())->toBe(0);
});

it('refuses any provider when oauth is disabled (case e)', function () {
    config()->set('larafoundry.auth.oauth.enabled', false);

    $response = $this->get(route('larafoundry.oauth.callback', ['provider' => 'google']));

    $response->assertRedirect('/');
    $response->assertSessionHas('error');
    expect(Auth::check())->toBeFalse();
});

it('refuses when the provider shares no email', function () {
    fakeSocialiteReturns(socialUser('gid-noemail', ''));

    $response = $this->get(route('larafoundry.oauth.callback', ['provider' => 'google']));

    $response->assertRedirect('/');
    $response->assertSessionHas('error');
    expect(User::count())->toBe(0);
});

it('redirects off to the provider on the redirect route', function () {
    $driver = Mockery::mock();
    $driver->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.test/o/oauth2'));
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

    $this->get(route('larafoundry.oauth.redirect', ['provider' => 'google']))
        ->assertRedirect('https://accounts.google.test/o/oauth2');
});
