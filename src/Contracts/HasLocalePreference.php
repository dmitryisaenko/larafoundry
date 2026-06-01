<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Contracts;

use Dmitryisaenko\LaraFoundry\Http\Middleware\SetLocale;

/**
 * A user that carries a persisted locale preference.
 *
 * Implemented by the host's authenticatable model when it stores a locale
 * column. {@see SetLocale} reads
 * and writes through this contract instead of touching a hard-coded property,
 * so the core never assumes the column exists.
 */
interface HasLocalePreference
{
    /**
     * The user's preferred locale, or null when none is stored.
     */
    public function preferredLocale(): ?string;

    /**
     * Persist a newly resolved locale on the user.
     */
    public function setPreferredLocale(string $locale): void;
}
