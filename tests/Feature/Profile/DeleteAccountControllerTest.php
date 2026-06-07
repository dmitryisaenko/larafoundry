<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('deletes a password account confirmed with the current password', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    $this->actingAs($user)
        ->delete('/profile/account', ['current_password' => 'secret-pass'])
        ->assertRedirect();

    expect($user->fresh()->user_deleted_at)->not->toBeNull();
});

it('refuses a password account when the password is wrong', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    $this->actingAs($user)
        ->delete('/profile/account', ['current_password' => 'wrong-pass'])
        ->assertSessionHasErrors('current_password');

    expect($user->fresh()->user_deleted_at)->toBeNull();
});

it('deletes an OAuth-only account confirmed with its email and the checkbox', function () {
    $user = User::create(['name' => 'A', 'email' => 'oauth@x.test']);
    $user->forceFill(['password' => null, 'provider_name' => 'google', 'provider_id' => 'gid'])->save();

    $this->actingAs($user)
        ->delete('/profile/account', ['email' => 'oauth@x.test', 'confirm_deletion' => true])
        ->assertRedirect();

    expect($user->fresh()->user_deleted_at)->not->toBeNull();
});

it('matches the OAuth-only email case-insensitively', function () {
    $user = User::create(['name' => 'A', 'email' => 'oauth@x.test']);
    $user->forceFill(['password' => null, 'provider_name' => 'google'])->save();

    $this->actingAs($user)
        ->delete('/profile/account', ['email' => 'OAUTH@X.TEST', 'confirm_deletion' => true])
        ->assertRedirect();

    expect($user->fresh()->user_deleted_at)->not->toBeNull();
});

it('refuses an OAuth-only account when the email does not match', function () {
    $user = User::create(['name' => 'A', 'email' => 'oauth@x.test']);
    $user->forceFill(['password' => null, 'provider_name' => 'google'])->save();

    $this->actingAs($user)
        ->delete('/profile/account', ['email' => 'someone-else@x.test', 'confirm_deletion' => true])
        ->assertSessionHasErrors('email');

    expect($user->fresh()->user_deleted_at)->toBeNull();
});

it('refuses an OAuth-only account when the confirmation box is unchecked', function () {
    $user = User::create(['name' => 'A', 'email' => 'oauth@x.test']);
    $user->forceFill(['password' => null, 'provider_name' => 'google'])->save();

    $this->actingAs($user)
        ->delete('/profile/account', ['email' => 'oauth@x.test', 'confirm_deletion' => false])
        ->assertSessionHasErrors('confirm_deletion');

    expect($user->fresh()->user_deleted_at)->toBeNull();
});
