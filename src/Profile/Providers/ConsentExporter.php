<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Providers;

use Dmitryisaenko\LaraFoundry\Profile\Contracts\ExportsUserDataProvider;
use Dmitryisaenko\LaraFoundry\Settings\Facades\Settings;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Exports the user's consent state — the GDPR record of what they agreed to
 * (phase 5.3).
 *
 * The cookie choice and the accepted Terms version/timestamp live in the generic
 * settings store (decision D-5.3-8: no bespoke consent table). Read with the
 * user's explicit scope id so the export is the subject's own record, never the
 * acting request's resolved scope.
 */
class ConsentExporter implements ExportsUserDataProvider
{
    /**
     * @return array<string, mixed>
     */
    public function exportFor(Authenticatable $user): array
    {
        $scopeId = $user->getAuthIdentifier();

        return [
            'cookie_consent' => Settings::get('cookie_consent', $scopeId),
            'terms_accepted_version' => Settings::get('terms_accepted_version', $scopeId),
            'terms_accepted_at' => Settings::get('terms_accepted_at', $scopeId),
        ];
    }

    public function key(): string
    {
        return 'consent';
    }

    public function priority(): int
    {
        return 30;
    }
}
