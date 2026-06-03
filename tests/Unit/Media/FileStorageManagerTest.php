<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Media\Contracts\MediaStorage;
use Dmitryisaenko\LaraFoundry\Media\Support\FileStorageManager;
use Dmitryisaenko\LaraFoundry\Media\Support\StoredFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The FileStorageManager writes through a configured disk with generated
 * filenames (phase 2.4). Storage::fake keeps everything in memory — no real
 * files are touched.
 */
beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
    $this->manager = app(FileStorageManager::class);
});

it('binds the MediaStorage contract to the file manager', function () {
    expect(app(MediaStorage::class))
        ->toBeInstanceOf(FileStorageManager::class);
});

it('stores an image under a date-sharded uuid path on the configured disk', function () {
    $stored = $this->manager->store(UploadedFile::fake()->image('photo.png', 50, 50), 'avatars');

    expect($stored)->toBeInstanceOf(StoredFile::class)
        ->and($stored->disk)->toBe('public')
        ->and($stored->mime)->toBe('image/jpeg') // images are normalised to jpg
        ->and($stored->filename)->toMatch('/^[0-9a-f\-]{36}\.jpg$/')
        ->and($stored->path)->toMatch('#^avatars/\d{4}/\d{2}/[0-9a-f\-]{36}\.jpg$#');

    Storage::disk('public')->assertExists($stored->path);
});

it('never lets a client-supplied name steer the path (traversal guard)', function () {
    // A malicious original name must not appear in the stored path — the path is
    // built from a uuid, so traversal segments cannot escape the directory.
    $stored = $this->manager->store(
        UploadedFile::fake()->image('../../../../etc/passwd.png', 10, 10),
        'avatars',
    );

    expect($stored->path)->not->toContain('..')
        ->and($stored->path)->not->toContain('passwd')
        ->and($stored->path)->toStartWith('avatars/');
});

it('writes named image variants alongside the original', function () {
    config()->set('larafoundry-media.image_variants.thumb', ['method' => 'scale', 'height' => 16]);

    $stored = $this->manager->store(
        UploadedFile::fake()->image('photo.png', 100, 100),
        'avatars',
        ['variants' => ['thumb']],
    );

    expect($stored->variants)->toHaveKey('thumb');
    Storage::disk('public')->assertExists($stored->path);
    Storage::disk('public')->assertExists($stored->variants['thumb']);
});

it('honours a per-call disk override', function () {
    $stored = $this->manager->store(
        UploadedFile::fake()->image('photo.png', 10, 10),
        'docs',
        ['disk' => 'local'],
    );

    expect($stored->disk)->toBe('local');
    Storage::disk('local')->assertExists($stored->path);
    Storage::disk('public')->assertMissing($stored->path);
});

it('stores a non-image upload as-is without image processing', function () {
    $stored = $this->manager->store(
        UploadedFile::fake()->create('report.pdf', 4, 'application/pdf'),
        'docs',
        ['disk' => 'local'],
    );

    expect($stored->filename)->toEndWith('.pdf');
    Storage::disk('local')->assertExists($stored->path);
});

it('deletes idempotently (a missing path is a no-op, not an error)', function () {
    $stored = $this->manager->store(UploadedFile::fake()->image('photo.png', 10, 10), 'avatars');

    expect($this->manager->delete($stored->path))->toBeTrue();
    Storage::disk('public')->assertMissing($stored->path);

    // Second delete of the now-missing file must not throw.
    expect($this->manager->delete($stored->path))->toBeTrue();
});

it('resolves a public url for a stored path', function () {
    $stored = $this->manager->store(UploadedFile::fake()->image('photo.png', 10, 10), 'avatars');

    expect($this->manager->url($stored->path))->toContain($stored->path);
});
