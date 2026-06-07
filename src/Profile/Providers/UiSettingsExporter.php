<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Providers;

use Dmitryisaenko\LaraFoundry\Profile\Contracts\ExportsUserDataProvider;
use Dmitryisaenko\LaraFoundry\Profile\Support\UiSettings;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Exports the user's resolved UI preferences — theme, density, sidebar… (phase
 * 5.3).
 *
 * Reads through {@see UiSettings::resolved()} so the export is the same
 * allowlisted, fail-closed view the appearance form shows: any non-allowlisted
 * key that may linger in the JSON column is dropped, not exported.
 */
class UiSettingsExporter implements ExportsUserDataProvider
{
    /**
     * @return array<string, mixed>
     */
    public function exportFor(Authenticatable $user): array
    {
        return UiSettings::resolved($user);
    }

    public function key(): string
    {
        return 'ui_settings';
    }

    public function priority(): int
    {
        return 20;
    }
}
