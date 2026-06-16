<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Http\Middleware\HandleInertiaRequests;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

/**
 * Resolve the `ui_settings` shared prop the way Inertia does (the share value is
 * a closure). The composable useDateFormat() reads this global prop, so the core
 * must ship it for the date_format preference to take effect out of the box.
 */
function resolveSharedUiSettings(?User $user): mixed
{
    $request = Request::create('/', 'GET');
    $request->setUserResolver(fn () => $user);

    $shared = (new HandleInertiaRequests)->share($request);
    $value = $shared['ui_settings'];

    return is_callable($value) ? $value() : $value;
}

// These run against the REAL package config (no override) to prove the shipped
// date_format preference is registered and enforced through the ui_settings path.

it('registers date_format in ui_settings with an auto default and the four options', function () {
    $registry = config('larafoundry.profile.ui_settings');

    expect($registry)->toHaveKey('date_format');
    expect($registry['date_format']['default'])->toBe('auto');
    expect($registry['date_format']['in'])->toContain('auto', 'dmy', 'mdy', 'iso');
});

it('saves a valid date_format preference', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    $this->actingAs($user)
        ->put('/profile/ui-settings', ['key' => 'date_format', 'value' => 'dmy'])
        ->assertRedirect();

    expect($user->fresh()->ui_settings['date_format'])->toBe('dmy');
});

it('rejects a date_format value outside the enum (fail-closed)', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    $this->actingAs($user)
        ->put('/profile/ui-settings', ['key' => 'date_format', 'value' => 'rainbow'])
        ->assertSessionHasErrors('value');

    expect($user->fresh()->ui_settings)->toBeNull();
});

it('shares the user-resolved ui_settings (incl. date_format) as a global prop', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $user->forceFill(['ui_settings' => ['date_format' => 'dmy']])->save();

    $resolved = resolveSharedUiSettings($user);

    expect($resolved)->toBeArray()
        ->and($resolved['date_format'])->toBe('dmy');
});

it('shares null ui_settings for a guest', function () {
    expect(resolveSharedUiSettings(null))->toBeNull();
});
