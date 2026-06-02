<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Models\UserSession;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('returns the stored preferred locale, null when blank', function () {
    $user = new User;
    expect($user->preferredLocale())->toBeNull();

    $user->forceFill(['locale' => 'de']);
    expect($user->preferredLocale())->toBe('de');

    $user->forceFill(['locale' => '']);
    expect($user->preferredLocale())->toBeNull();
});

it('persists a preferred locale', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    $user->setPreferredLocale('uk');

    expect($user->fresh()->locale)->toBe('uk');
});

it('reports blocked / deleted from the lifecycle columns', function () {
    $user = new User;

    expect($user->isBlocked())->toBeFalse()
        ->and($user->isDeleted())->toBeFalse();

    $user->forceFill(['user_blocked_at' => now(), 'user_deleted_at' => now()]);

    expect($user->isBlocked())->toBeTrue()
        ->and($user->isDeleted())->toBeTrue();
});

it('reports oauth-only when password is null and a provider is linked', function () {
    $oauth = new User;
    $oauth->forceFill(['password' => null, 'provider_name' => 'google']);
    expect($oauth->isOauthOnly())->toBeTrue();

    $local = new User;
    $local->forceFill(['password' => 'hash', 'provider_name' => 'google']);
    expect($local->isOauthOnly())->toBeFalse();

    $plain = new User;
    $plain->forceFill(['password' => null, 'provider_name' => null]);
    expect($plain->isOauthOnly())->toBeFalse();
});

it('reflects the raw is_admin flag', function () {
    $user = new User;
    expect($user->isAdmin())->toBeFalse();

    $user->forceFill(['is_admin' => true]);
    expect($user->isAdmin())->toBeTrue();
});

it('hashes the password via the hashed cast and never stores plaintext', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'super-secret']);

    expect($user->password)->not->toBe('super-secret');
    expect(Hash::check('super-secret', $user->password))->toBeTrue();
});

it('hides secret columns from serialization', function () {
    $user = User::create([
        'name' => 'A',
        'email' => 'a@x.test',
        'password' => 'super-secret',
        'provider_token' => 'tok',
        'provider_refresh_token' => 'ref',
    ]);
    $user->forceFill(['two_factor_secret' => 'xyz'])->save();

    $array = $user->fresh()->toArray();

    expect($array)->not->toHaveKeys([
        'password',
        'remember_token',
        'provider_token',
        'provider_refresh_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ]);
    expect($array)->toHaveKey('email');
});

it('exposes a sessions HasMany relation', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    expect($user->sessions())->toBeInstanceOf(HasMany::class);

    $user->sessions()->create(['session_id' => 'sess-1']);

    expect($user->sessions()->first())->toBeInstanceOf(UserSession::class)
        ->and($user->sessions()->first()->session_id)->toBe('sess-1');
});
