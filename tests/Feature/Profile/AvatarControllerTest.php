<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

it('stores an uploaded avatar on the media disk', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    $this->actingAs($user)
        ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg', 400, 400)])
        ->assertRedirect();

    $stored = $user->fresh()->avatar;
    expect($stored)->not->toBeNull();
    Storage::disk('public')->assertExists($stored);
});

it('rejects a non-image upload', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    $this->actingAs($user)
        ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('avatar');

    expect($user->fresh()->avatar)->toBeNull();
});

it('replaces the previous avatar file on re-upload', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    $this->actingAs($user)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('one.jpg', 400, 400)]);
    $first = $user->fresh()->avatar;

    $this->actingAs($user)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('two.jpg', 400, 400)]);
    $second = $user->fresh()->avatar;

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertExists($second);
    Storage::disk('public')->assertMissing($first);
});

it('removes the avatar and deletes its stored file', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    $this->actingAs($user)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg', 400, 400)]);
    $stored = $user->fresh()->avatar;
    Storage::disk('public')->assertExists($stored);

    $this->actingAs($user)->delete('/profile/avatar')->assertRedirect();

    expect($user->fresh()->avatar)->toBeNull();
    Storage::disk('public')->assertMissing($stored);
});

it('leaves an external OAuth avatar URL untouched when replaced', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $user->forceFill(['avatar' => 'https://cdn.example.test/a.jpg'])->save();

    // Re-uploading must not attempt to delete the external URL as a disk path.
    $this->actingAs($user)
        ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg', 400, 400)])
        ->assertRedirect();

    $stored = $user->fresh()->avatar;
    expect($stored)->not->toBe('https://cdn.example.test/a.jpg');
    Storage::disk('public')->assertExists($stored);
});
