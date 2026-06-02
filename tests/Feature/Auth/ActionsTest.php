<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Actions\CreateNewUser;
use Dmitryisaenko\LaraFoundry\Auth\Actions\ResetUserPassword;
use Dmitryisaenko\LaraFoundry\Auth\Actions\UpdateUserPassword;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('creates a user from valid registration input', function () {
    $user = (new CreateNewUser)->create([
        'name' => 'Jane',
        'email' => 'jane@x.test',
        'password' => 'long-enough-pass',
        'password_confirmation' => 'long-enough-pass',
    ]);

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->email)->toBe('jane@x.test')
        ->and($user->locale)->toBe(config('larafoundry.locale.default'))
        ->and(Hash::check('long-enough-pass', $user->password))->toBeTrue();
});

it('rejects a duplicate email on registration', function () {
    User::create(['name' => 'A', 'email' => 'dup@x.test', 'password' => 'secret-pass']);

    expect(fn () => (new CreateNewUser)->create([
        'name' => 'B',
        'email' => 'dup@x.test',
        'password' => 'long-enough-pass',
        'password_confirmation' => 'long-enough-pass',
    ]))->toThrow(ValidationException::class);
});

it('rejects a password shorter than the configured minimum', function () {
    config()->set('larafoundry.auth.password_min_length', 8);

    expect(fn () => (new CreateNewUser)->create([
        'name' => 'B',
        'email' => 'short@x.test',
        'password' => 'short',
        'password_confirmation' => 'short',
    ]))->toThrow(ValidationException::class);
});

it('rejects an unconfirmed password on registration', function () {
    expect(fn () => (new CreateNewUser)->create([
        'name' => 'B',
        'email' => 'noconf@x.test',
        'password' => 'long-enough-pass',
        'password_confirmation' => 'different-pass',
    ]))->toThrow(ValidationException::class);
});

it('resets the password to a new hashed value', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'old-password']);

    (new ResetUserPassword)->reset($user, [
        'password' => 'brand-new-pass',
        'password_confirmation' => 'brand-new-pass',
    ]);

    expect(Hash::check('brand-new-pass', $user->fresh()->password))->toBeTrue();
});

it('rejects a too-short password on reset', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'old-password']);

    expect(fn () => (new ResetUserPassword)->reset($user, [
        'password' => 'x',
        'password_confirmation' => 'x',
    ]))->toThrow(ValidationException::class);
});

it('updates the password when the current password is correct', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'current-pass']);
    $this->actingAs($user);

    (new UpdateUserPassword)->update($user, [
        'current_password' => 'current-pass',
        'password' => 'next-good-pass',
        'password_confirmation' => 'next-good-pass',
    ]);

    expect(Hash::check('next-good-pass', $user->fresh()->password))->toBeTrue();
});

it('refuses to update the password when the current password is wrong', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'current-pass']);
    $this->actingAs($user);

    expect(fn () => (new UpdateUserPassword)->update($user, [
        'current_password' => 'wrong-pass',
        'password' => 'next-good-pass',
        'password_confirmation' => 'next-good-pass',
    ]))->toThrow(ValidationException::class);

    // Password unchanged.
    expect(Hash::check('current-pass', $user->fresh()->password))->toBeTrue();
});
