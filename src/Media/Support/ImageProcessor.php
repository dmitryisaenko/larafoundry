<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Media\Support;

use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use InvalidArgumentException;

/**
 * Thin wrapper over intervention/image for the core's resize/variant work
 * (phase 2.4).
 *
 * The donor scaled images inline in services tied to `public_path()`; here the
 * processing is disk-agnostic — it returns encoded bytes, and the
 * FileStorageManager decides where they land. Variant definitions come from
 * config (`larafoundry-media.image_variants`), so the host adds its own sizes
 * by publishing the config rather than editing core code.
 */
class ImageProcessor
{
    public function __construct(
        private readonly ImageManager $manager,
    ) {}

    /**
     * Decode an image source once, so the original and every variant share a
     * single decode rather than re-reading the bytes per output.
     *
     * @param  string  $source  raw image bytes or an absolute path
     */
    public function decode(string $source): ImageInterface
    {
        return $this->manager->read($source);
    }

    /**
     * Encode an already-decoded image to JPEG at the configured quality,
     * optionally resized by a named variant definition. The image is cloned
     * before any transform so the caller's instance can be reused for the next
     * variant unmodified.
     *
     * @param  string|null  $variant  key into `image_variants`, or null for the original size
     * @return string encoded image bytes
     */
    public function encode(ImageInterface $image, ?string $variant = null): string
    {
        if ($variant !== null) {
            $image = $this->applyVariant(clone $image, $variant);
        }

        $quality = (int) config('larafoundry-media.image_quality', 90);

        return (string) $image->toJpeg($quality);
    }

    /**
     * Apply a configured variant transform. `scaleDown` keeps the aspect ratio
     * and never upsizes (a small source is left at its own size, not blown up);
     * `cover` crops to exact width×height and requires both dimensions.
     */
    private function applyVariant(ImageInterface $image, string $variant): ImageInterface
    {
        $def = config("larafoundry-media.image_variants.{$variant}");

        if (! is_array($def)) {
            return $image;
        }

        $method = $def['method'] ?? 'scale';
        $width = $def['width'] ?? null;
        $height = $def['height'] ?? null;

        return match ($method) {
            'cover' => $this->cover($image, $width, $height, $variant),
            // scaleDown keeps ratio and never enlarges; one dimension scales by it.
            default => $image->scaleDown(
                $width !== null ? (int) $width : null,
                $height !== null ? (int) $height : null,
            ),
        };
    }

    /**
     * Crop-to-fill, guarding the config: a cover variant needs both positive
     * dimensions, otherwise intervention divides by a zero target ratio. A
     * clear exception beats a raw DivisionByZeroError when a host publishes a
     * half-filled variant.
     */
    private function cover(ImageInterface $image, mixed $width, mixed $height, string $variant): ImageInterface
    {
        if (! is_numeric($width) || ! is_numeric($height) || (int) $width < 1 || (int) $height < 1) {
            throw new InvalidArgumentException(
                "Image variant [{$variant}] uses method 'cover' but is missing a positive width and height."
            );
        }

        return $image->cover((int) $width, (int) $height);
    }
}
