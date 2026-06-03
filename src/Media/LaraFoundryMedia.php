<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Media;

use Dmitryisaenko\LaraFoundry\Media\Contracts\AvatarGenerator;
use Dmitryisaenko\LaraFoundry\Media\Contracts\MediaStorage;

/**
 * One entry point for resolving avatar/logo URLs across the core (phase 2.4).
 *
 * Model accessors (`User::getAvatarUrlAttribute`, `Company::getLogoUrlAttribute`)
 * call these so the "stored path vs external URL vs generated default" decision
 * lives in one place rather than being copy-pasted onto every model. Resolution
 * goes through the container-bound {@see MediaStorage} / {@see AvatarGenerator},
 * so a host swapping either implementation changes every accessor at once.
 */
class LaraFoundryMedia
{
    /**
     * Resolve a displayable avatar URL for a stored value, generating an
     * initials placeholder when there is nothing stored.
     *
     * The stored value can be one of three things, which is why it cannot be a
     * naive `Storage::url()` (recon finding #5 — the avatar column mixes them):
     *   - empty            → generate an initials placeholder from $seed
     *   - an absolute URL  → an external avatar (e.g. from an OAuth provider) —
     *                         returned as-is, never re-prefixed with the disk URL
     *   - a relative path  → a file we stored → resolve through MediaStorage
     */
    public static function avatarUrl(?string $stored, string $seed): string
    {
        $stored = is_string($stored) ? trim($stored) : '';

        if ($stored === '') {
            return app(AvatarGenerator::class)->url($seed);
        }

        if (self::isAbsoluteUrl($stored)) {
            return $stored;
        }

        return app(MediaStorage::class)->url($stored);
    }

    /**
     * Resolve a company logo URL for a stored path, or null when none is stored.
     *
     * Logos are not auto-placeholdered here (the UI decides whether to show a
     * generated company mark); callers that want a default fall back themselves.
     */
    public static function logoUrl(?string $storedPath): ?string
    {
        $storedPath = is_string($storedPath) ? trim($storedPath) : '';

        if ($storedPath === '') {
            return null;
        }

        if (self::isAbsoluteUrl($storedPath)) {
            return $storedPath;
        }

        return app(MediaStorage::class)->url($storedPath);
    }

    private static function isAbsoluteUrl(string $value): bool
    {
        return str_starts_with($value, 'http://')
            || str_starts_with($value, 'https://')
            || str_starts_with($value, 'data:');
    }
}
