<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Help\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * An uploaded help picture, re-encoded as webp when that makes it smaller.
 *
 * A help illustration is a screenshot that every reader downloads; webp is a
 * fraction of a png. Declines (and keeps the original bytes and extension) when the
 * picture is already webp, is a gif (an animation would lose its frames), cannot be
 * decoded, the PHP build has no webp support, or the result came out bigger.
 */
final class WebpPicture
{
    private const QUALITY = 82;

    /**
     * @return array{0: string, 1: string} the bytes to store and their extension
     */
    public static function from(string $bytes, string $ext): array
    {
        $ext = strtolower($ext);

        if ($ext === 'webp' || $ext === 'gif') {
            return [$bytes, $ext];
        }

        if (! function_exists('imagewebp') || ! function_exists('imagecreatefromstring')) {
            Log::warning('help picture: webp conversion skipped, this PHP has no webp support');

            return [$bytes, $ext];
        }

        try {
            $image = @imagecreatefromstring($bytes);

            if ($image === false) {
                return [$bytes, $ext];
            }

            // A paletted png must become truecolor first, and keep its transparency.
            imagepalettetotruecolor($image);
            imagealphablending($image, false);
            imagesavealpha($image, true);

            ob_start();
            $ok = imagewebp($image, null, self::QUALITY);
            $webp = (string) ob_get_clean();

            if (! $ok || $webp === '') {
                return [$bytes, $ext];
            }

            return strlen($webp) < strlen($bytes) ? [$webp, 'webp'] : [$bytes, $ext];
        } catch (Throwable $e) {
            report($e);

            return [$bytes, $ext];
        }
    }
}
