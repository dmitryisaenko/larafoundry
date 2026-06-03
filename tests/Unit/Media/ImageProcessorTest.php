<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Media\Support\ImageProcessor;
use Intervention\Image\ImageManager;

/**
 * The ImageProcessor encodes/resizes through intervention by config-driven
 * variant (phase 2.4). Disk-agnostic: it returns bytes, the storage decides
 * where they land.
 */
beforeEach(function () {
    $this->processor = app(ImageProcessor::class);
});

// Build image bytes in memory via intervention itself (not UploadedFile::fake,
// whose temp file is unlinked the moment the object goes out of scope — flaky
// on some platforms). encode() takes raw bytes, mirroring a real request.
function imageBytes(int $w, int $h): string
{
    return (string) app(ImageManager::class)->create($w, $h)->fill('#3366cc')->toJpeg();
}

it('encodes a decoded image to jpeg bytes', function () {
    $image = $this->processor->decode(imageBytes(80, 80));
    $bytes = $this->processor->encode($image);

    // JPEG SOI marker.
    expect(substr($bytes, 0, 2))->toBe("\xFF\xD8");
});

it('scales DOWN by a configured variant, preserving aspect ratio', function () {
    config()->set('larafoundry-media.image_variants.thumb', ['method' => 'scale', 'height' => 20]);

    $image = $this->processor->decode(imageBytes(100, 100));
    $bytes = $this->processor->encode($image, 'thumb');

    $read = app(ImageManager::class)->read($bytes);
    expect($read->height())->toBe(20)
        ->and($read->width())->toBe(20); // square in, square out
});

it('never upsizes a small source (scaleDown)', function () {
    config()->set('larafoundry-media.image_variants.big', ['method' => 'scale', 'height' => 256]);

    // Source smaller than the target height — must stay at its own size.
    $image = $this->processor->decode(imageBytes(40, 40));
    $bytes = $this->processor->encode($image, 'big');

    $read = app(ImageManager::class)->read($bytes);
    expect($read->height())->toBe(40)
        ->and($read->width())->toBe(40);
});

it('crops to exact dimensions for a cover variant', function () {
    config()->set('larafoundry-media.image_variants.square', ['method' => 'cover', 'width' => 30, 'height' => 30]);

    $image = $this->processor->decode(imageBytes(100, 60));
    $bytes = $this->processor->encode($image, 'square');

    $read = app(ImageManager::class)->read($bytes);
    expect($read->width())->toBe(30)
        ->and($read->height())->toBe(30);
});

it('throws a clear error for a cover variant missing a dimension', function () {
    config()->set('larafoundry-media.image_variants.bad', ['method' => 'cover', 'height' => 30]);

    $image = $this->processor->decode(imageBytes(50, 50));

    expect(fn () => $this->processor->encode($image, 'bad'))
        ->toThrow(InvalidArgumentException::class);
});

it('ignores an unknown variant key and encodes at original size', function () {
    $image = $this->processor->decode(imageBytes(40, 40));
    $bytes = $this->processor->encode($image, 'does-not-exist');
    $read = app(ImageManager::class)->read($bytes);

    expect($read->width())->toBe(40);
});

it('does not mutate the source image when encoding a variant (clone)', function () {
    config()->set('larafoundry-media.image_variants.thumb', ['method' => 'scale', 'height' => 10]);

    $image = $this->processor->decode(imageBytes(60, 60));
    $this->processor->encode($image, 'thumb'); // variant encode

    // The original instance must be untouched, so the next encode is full-size.
    $bytes = $this->processor->encode($image);
    $read = app(ImageManager::class)->read($bytes);
    expect($read->width())->toBe(60);
});
