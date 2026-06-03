<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Media\Contracts\MediaStorage;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

/*
 * Phase 2.4: private files live on a non-public disk and are reachable only
 * through a short-lived SIGNED url that also requires auth (recon finding #7).
 */

function privateUser(): User
{
    return User::create([
        'name' => 'Reader',
        'email' => 'reader'.uniqid().'@x.test',
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

it('mints a signed temporary url for a file on the private disk', function () {
    Storage::fake('local');
    $stored = app(MediaStorage::class)->store(
        UploadedFile::fake()->create('invoice.pdf', 4, 'application/pdf'),
        'docs',
        ['disk' => 'local'],
    );

    $url = app(MediaStorage::class)->temporaryUrl($stored->path, 5);

    expect($url)->toContain('larafoundry/media/private')
        ->and($url)->toContain('signature=');
});

it('streams the file to an authenticated user with a valid signature', function () {
    Storage::fake('local');
    $stored = app(MediaStorage::class)->store(
        UploadedFile::fake()->create('invoice.pdf', 4, 'application/pdf'),
        'docs',
        ['disk' => 'local'],
    );
    $url = app(MediaStorage::class)->temporaryUrl($stored->path, 5);

    $this->actingAs(privateUser())->get($url)->assertOk();
});

it('rejects an unsigned request to the private route', function () {
    $this->actingAs(privateUser())
        ->get('/larafoundry/media/private?path=docs/anything.pdf')
        ->assertStatus(403); // signed middleware
});

it('redirects a guest to login even with a valid signature', function () {
    Storage::fake('local');
    $stored = app(MediaStorage::class)->store(
        UploadedFile::fake()->create('invoice.pdf', 4, 'application/pdf'),
        'docs',
        ['disk' => 'local'],
    );
    $url = app(MediaStorage::class)->temporaryUrl($stored->path, 5);

    // No actingAs: the 'auth' middleware must bounce a guest.
    $this->get($url)->assertRedirect();
});

it('serves a file stored on a non-default private disk via the signed disk', function () {
    Storage::fake('local');
    Storage::fake('invoices');
    config()->set('larafoundry-media.private_disk', 'invoices');

    $stored = app(MediaStorage::class)->store(
        UploadedFile::fake()->create('invoice.pdf', 4, 'application/pdf'),
        'docs',
        ['disk' => 'invoices'],
    );
    $url = app(MediaStorage::class)->temporaryUrl($stored->path, 5, 'invoices');

    expect($url)->toContain('disk=invoices');
    $this->actingAs(privateUser())->get($url)->assertOk();
});

it('rejects a signed url pointing at a disk other than the private disk', function () {
    Storage::fake('local');
    config()->set('larafoundry-media.private_disk', 'local');

    // Mint a validly-signed URL but for the public disk — controller must 403.
    $url = URL::temporarySignedRoute(
        'larafoundry.media.private',
        now()->addMinutes(5),
        ['path' => 'whatever.pdf', 'disk' => 'public'],
    );

    $this->actingAs(privateUser())->get($url)->assertStatus(403);
});

it('honours the configured temporary_url_minutes default', function () {
    Storage::fake('local');
    config()->set('larafoundry-media.temporary_url_minutes', 1);

    $stored = app(MediaStorage::class)->store(
        UploadedFile::fake()->create('x.pdf', 2, 'application/pdf'),
        'docs',
        ['disk' => 'local'],
    );

    // No minutes arg -> uses the config default; URL is still well-formed.
    $url = app(MediaStorage::class)->temporaryUrl($stored->path);
    expect($url)->toContain('larafoundry/media/private');
    $this->actingAs(privateUser())->get($url)->assertOk();
});

it('404s when the signed path does not exist on the private disk', function () {
    Storage::fake('local');
    // Sign a url by hand via the storage so the signature is valid but the file
    // is absent.
    $url = app(MediaStorage::class)->temporaryUrl('docs/missing.pdf', 5);

    $this->actingAs(privateUser())->get($url)->assertNotFound();
});
