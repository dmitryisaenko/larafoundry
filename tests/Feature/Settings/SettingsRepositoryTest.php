<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Settings\Facades\Settings;
use Dmitryisaenko\LaraFoundry\Settings\Models\Setting;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('larafoundry.settings', [
        'a_email' => ['scope' => 'app', 'type' => 'string', 'default' => null, 'validation' => ['nullable', 'email'], 'public' => true],
        'a_secret' => ['scope' => 'app', 'type' => 'string', 'default' => null, 'validation' => ['nullable', 'string']],
        'c_tz' => ['scope' => 'company', 'type' => 'string', 'default' => 'UTC', 'validation' => ['string']],
        'u_flag' => ['scope' => 'user', 'type' => 'boolean', 'default' => true, 'validation' => ['boolean']],
        'u_consent' => ['scope' => 'user', 'type' => 'boolean', 'default' => false, 'validation' => ['boolean'], 'form' => false],
    ]);
});

it('returns the registry default when nothing is stored', function () {
    expect(Settings::get('a_email'))->toBeNull();
});

it('writes and reads back a value cast to its declared type', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $this->actingAs($user);

    Settings::set('u_flag', '0');

    expect(Settings::get('u_flag'))->toBeFalse();
});

it('is fail-closed: writing an unregistered key throws', function () {
    expect(fn () => Settings::set('nope', 'x'))->toThrow(RuntimeException::class);
});

it('returns the supplied default for an unregistered read', function () {
    expect(Settings::get('nope', null, 'fallback'))->toBe('fallback');
});

it('validates the value against the registry rule', function () {
    expect(fn () => Settings::set('a_email', 'not-an-email'))->toThrow(ValidationException::class);
});

it('isolates user-scope values per user', function () {
    $a = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $b = User::create(['name' => 'B', 'email' => 'b@x.test', 'password' => 'secret-pass']);

    $this->actingAs($a);
    Settings::set('u_flag', false);

    $this->actingAs($b);
    expect(Settings::get('u_flag'))->toBeTrue();

    $this->actingAs($a);
    expect(Settings::get('u_flag'))->toBeFalse();
});

it('scopes company settings by company id', function () {
    Settings::set('c_tz', 'Europe/Kyiv', 7);

    expect(Settings::get('c_tz', 7))->toBe('Europe/Kyiv')
        ->and(Settings::get('c_tz', 8))->toBe('UTC');
});

it('busts the cache on write', function () {
    expect(Settings::get('c_tz', 7))->toBe('UTC');

    Settings::set('c_tz', 'Mars', 7);

    expect(Settings::get('c_tz', 7))->toBe('Mars');
});

it('exposes only public app settings', function () {
    Settings::set('a_email', 'help@x.test');
    Settings::set('a_secret', 'hidden');

    $public = Settings::publicSettings();

    expect($public)->toHaveKey('a_email')
        ->and($public['a_email'])->toBe('help@x.test')
        ->and($public)->not->toHaveKey('a_secret');
});

it('keeps one row per app key (sentinel + unique)', function () {
    Settings::set('a_email', 'a@x.test');
    Settings::set('a_email', 'b@x.test');

    expect(Setting::query()->where('key', 'a_email')->count())->toBe(1)
        ->and(Settings::get('a_email'))->toBe('b@x.test');
});

it('merges stored values with defaults for a scope', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $this->actingAs($user);
    Settings::set('u_flag', false);

    $all = Settings::allForScope('user');

    expect($all['u_flag'])->toBeFalse()
        ->and($all)->toHaveKey('u_consent')
        ->and($all['u_consent'])->toBeFalse();
});
