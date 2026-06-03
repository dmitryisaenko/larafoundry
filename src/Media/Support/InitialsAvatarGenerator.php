<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Media\Support;

use Dmitryisaenko\LaraFoundry\Media\Contracts\AvatarGenerator;
use Laravolt\Avatar\Avatar;

/**
 * Default {@see AvatarGenerator}: initials rendered inline as an SVG data URI,
 * via laravolt/avatar (phase 2.4).
 *
 * Returning an SVG data URI (not a stored PNG/JPEG) is deliberate. It needs no
 * GD/Imagick extension (laravolt's `toSvg()` is pure string building), it never
 * writes a file, so a user with no uploaded avatar produces zero orphans (recon
 * finding #3), and the result is derived purely from the seed — the same name
 * always yields the same colour, with no persistence to keep in sync.
 *
 * The colour is picked deterministically from the seed by laravolt, so two
 * users with different names get visually distinct placeholders.
 */
class InitialsAvatarGenerator implements AvatarGenerator
{
    public function url(string $seed, array $options = []): string
    {
        $config = config('larafoundry-media.avatar', []);

        $size = (int) ($options['size'] ?? $config['size'] ?? 256);
        $shape = (string) ($options['shape'] ?? $config['shape'] ?? 'circle');
        $chars = (int) ($options['chars'] ?? $config['chars'] ?? 2);

        $avatar = new Avatar([
            'driver' => 'gd', // unused by toSvg(), but laravolt reads the key
            'shape' => $shape,
            'chars' => $chars,
            'width' => $size,
            'height' => $size,
            'fontSize' => (int) round($size * 0.5),
            'border' => ['size' => 0, 'color' => 'background', 'radius' => 0],
        ]);

        $svg = $avatar->create($this->seedLabel($seed))->toSvg();

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * A non-empty label to derive initials from; falls back to a neutral glyph
     * so an empty/whitespace name still renders a placeholder rather than blank.
     */
    private function seedLabel(string $seed): string
    {
        $seed = trim($seed);

        return $seed !== '' ? $seed : '?';
    }
}
