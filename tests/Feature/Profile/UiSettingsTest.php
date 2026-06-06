<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('larafoundry.profile.ui_settings', [
        'theme' => ['type' => 'string', 'default' => 'system', 'in' => ['light', 'dark', 'system']],
        'sidebar_collapsed' => ['type' => 'boolean', 'default' => false],
    ]);
});

it('saves an allowlisted preference, cast to its type', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    $this->actingAs($user)
        ->put('/profile/ui-settings', ['key' => 'sidebar_collapsed', 'value' => '1'])
        ->assertRedirect();

    expect($user->fresh()->ui_settings['sidebar_collapsed'])->toBeTrue();
});

it('rejects a key that is not on the allowlist', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    $this->actingAs($user)
        ->put('/profile/ui-settings', ['key' => 'is_admin', 'value' => true])
        ->assertSessionHasErrors('key');

    expect($user->fresh()->ui_settings)->toBeNull();
});

it('rejects a value outside the declared enum', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    $this->actingAs($user)
        ->put('/profile/ui-settings', ['key' => 'theme', 'value' => 'rainbow'])
        ->assertSessionHasErrors('value');

    expect($user->fresh()->ui_settings)->toBeNull();
});

it('prunes non-allowlisted keys already lingering in the column', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $user->forceFill(['ui_settings' => ['evil' => 'x', 'theme' => 'system']])->save();

    $this->actingAs($user)
        ->put('/profile/ui-settings', ['key' => 'theme', 'value' => 'dark'])
        ->assertRedirect();

    $stored = $user->fresh()->ui_settings;
    expect($stored)->toHaveKey('theme')
        ->and($stored['theme'])->toBe('dark')
        ->and($stored)->not->toHaveKey('evil');
});
