<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Actions\UpdateUserProfileInformation;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('updates plain profile fields without asking for a password', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $this->actingAs($user);

    (new UpdateUserProfileInformation)->update($user, [
        'name' => 'New Name',
        'email' => 'a@x.test',
        'phone' => '+100',
    ]);

    $fresh = $user->fresh();
    expect($fresh->name)->toBe('New Name')
        ->and($fresh->phone)->toBe('+100');
});

it('refuses to change the email without the current password', function () {
    Event::fake([Registered::class]);

    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $user->forceFill(['email_verified_at' => now()])->save();
    $this->actingAs($user);

    expect(fn () => (new UpdateUserProfileInformation)->update($user, [
        'name' => 'A',
        'email' => 'new@x.test',
    ]))->toThrow(ValidationException::class);

    expect($user->fresh()->email)->toBe('a@x.test');
    Event::assertNotDispatched(Registered::class);
});

it('changes the email with the right password, resets verification and re-verifies', function () {
    Event::fake([Registered::class]);

    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $user->forceFill(['email_verified_at' => now()])->save();
    $this->actingAs($user);

    (new UpdateUserProfileInformation)->update($user, [
        'name' => 'A',
        'email' => 'new@x.test',
        'current_password' => 'secret-pass',
    ]);

    $fresh = $user->fresh();
    expect($fresh->email)->toBe('new@x.test')
        ->and($fresh->email_verified_at)->toBeNull();

    Event::assertDispatched(Registered::class);
});

it('signs out other devices when the email changes', function () {
    Event::fake([Registered::class]);

    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $this->actingAs($user);

    $currentId = app('session.store')->getId();
    $user->sessions()->create(['session_id' => $currentId, 'login_method' => 'native']);
    $user->sessions()->create(['session_id' => str_pad('other', 40, 'b'), 'login_method' => 'native']);

    (new UpdateUserProfileInformation)->update($user, [
        'name' => 'A',
        'email' => 'new@x.test',
        'current_password' => 'secret-pass',
    ]);

    expect($user->sessions()->pluck('session_id')->all())->toBe([$currentId]);
});

it('rejects changing the email to the reserved super-admin address', function () {
    config()->set('larafoundry.security.super_admin.email', 'boss@x.test');

    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $this->actingAs($user);

    expect(fn () => (new UpdateUserProfileInformation)->update($user, [
        'name' => 'A',
        'email' => 'boss@x.test',
        'current_password' => 'secret-pass',
    ]))->toThrow(ValidationException::class);

    expect($user->fresh()->email)->toBe('a@x.test');
});
