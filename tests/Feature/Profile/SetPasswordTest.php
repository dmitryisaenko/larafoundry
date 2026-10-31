<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\ImpersonateController;
use Dmitryisaenko\LaraFoundry\Admin\Support\ActingAsSomeoneElse;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * A first password for an account created through OAuth (v0.49.0): set once, never
 * over an existing password (that is the change flow, which checks the current one).
 */
uses(RefreshDatabase::class);

function passwordlessUser(): User
{
    $user = User::create(['name' => 'Ann', 'email' => 'ann@x.test', 'password' => 'placeholder']);
    $user->forceFill(['password' => null, 'provider_name' => 'google', 'provider_id' => 'gid'])->save();

    return $user->fresh();
}

it('sets a first password and keeps the user signed in', function () {
    $user = passwordlessUser();

    $this->actingAs($user)
        ->from('/profile')
        ->post('/profile/set-password', ['password' => 'Long-enough-1', 'password_confirmation' => 'Long-enough-1'])
        ->assertRedirect('/profile')
        ->assertSessionHas('message-info', __('larafoundry::profile.password.set'));

    expect(Hash::check('Long-enough-1', $user->fresh()->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

it('refuses an account that already has a password', function () {
    $user = User::create(['name' => 'Bob', 'email' => 'bob@x.test', 'password' => 'Old-password-1']);

    $this->actingAs($user)
        ->post('/profile/set-password', ['password' => 'Long-enough-1', 'password_confirmation' => 'Long-enough-1'])
        ->assertForbidden();

    expect(Hash::check('Old-password-1', $user->fresh()->password))->toBeTrue();
});

it('refuses an operator signed in as the user - a password of their own to it (review 2026-10-31)', function () {
    $user = passwordlessUser();
    $body = ['password' => 'Long-enough-1', 'password_confirmation' => 'Long-enough-1'];

    $this->actingAs($user)->withSession([ImpersonateController::SESSION_KEY => 99])
        ->post('/profile/set-password', $body)
        ->assertForbidden();

    // A host's own way of acting as a user counts the same.
    app(ActingAsSomeoneElse::class)->addCheck(fn (Request $request) => $request->session()->has('host.follow'));

    $this->actingAs($user)->withSession(['host.follow' => 1])
        ->post('/profile/set-password', $body)
        ->assertForbidden();

    expect($user->fresh()->password)->toBeNull();
});

it('wants the password twice', function () {
    $user = passwordlessUser();

    $this->actingAs($user)
        ->post('/profile/set-password', ['password' => 'Long-enough-1', 'password_confirmation' => 'Other-one-1'])
        ->assertSessionHasErrors('password');

    expect($user->fresh()->password)->toBeNull();
});

it('keeps a guest out', function () {
    $this->post('/profile/set-password', ['password' => 'Long-enough-1', 'password_confirmation' => 'Long-enough-1'])
        ->assertRedirect();
});

it('hides the preferences the host does not read and the two-factor note when told to', function () {
    $user = User::create(['name' => 'Ann', 'email' => 'ann@x.test', 'password' => 'secret-pass']);

    $keys = fn () => array_column(
        $this->actingAs($user)->withHeader('X-Inertia', 'true')->get('/profile')->json('props.uiSettingsSchema'),
        'key',
    );

    expect($keys())->toContain('table_density');

    config()->set('larafoundry.profile.hub', ['hidden_ui_settings' => ['table_density'], 'two_factor_note' => false]);

    expect($keys())->not->toContain('table_density')->toContain('theme');

    $this->actingAs($user)->withHeader('X-Inertia', 'true')->get('/profile')
        ->assertJsonPath('props.twoFactorNote', false);
});
