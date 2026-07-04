<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// These run against the REAL package config (no override) to prove the shipped
// time_format preference is registered and enforced through the ui_settings path.
// The generic ui_settings share is already covered by DateFormatSettingTest.

it('registers time_format in ui_settings with an auto default and 24h/12h options', function () {
    $registry = config('larafoundry.profile.ui_settings');

    expect($registry)->toHaveKey('time_format');
    expect($registry['time_format']['default'])->toBe('auto');
    expect($registry['time_format']['in'])->toContain('auto', '24h', '12h');
});

it('saves a valid time_format preference', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    $this->actingAs($user)
        ->put('/profile/ui-settings', ['key' => 'time_format', 'value' => '24h'])
        ->assertRedirect();

    expect($user->fresh()->ui_settings['time_format'])->toBe('24h');
});

it('rejects a time_format value outside the enum (fail-closed)', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    $this->actingAs($user)
        ->put('/profile/ui-settings', ['key' => 'time_format', 'value' => 'half-past'])
        ->assertSessionHasErrors('value');

    expect($user->fresh()->ui_settings)->toBeNull();
});
