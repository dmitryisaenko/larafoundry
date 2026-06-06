<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Providers;

use Dmitryisaenko\LaraFoundry\Profile\Contracts\ExportsUserDataProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Exports the identity the core owns — the baseline section of a user-data
 * export (phase 5.1 seam).
 *
 * Returns only the profile attributes the core defines; secrets (password, 2FA,
 * tokens) are never exported. Host/module providers add their own sections
 * (orders, tickets…) alongside this one.
 */
class CoreUserProfileExporter implements ExportsUserDataProvider
{
    /**
     * @return array<string, mixed>
     */
    public function exportFor(Authenticatable $user): array
    {
        $fields = [
            'name', 'lastname', 'middlename', 'email', 'phone',
            'country', 'sex', 'birth_date', 'locale',
            'created_at', 'last_login_at',
        ];

        $data = [];
        foreach ($fields as $field) {
            $value = $user->getAttribute($field);
            if ($value !== null) {
                $data[$field] = is_scalar($value) ? $value : (string) $value;
            }
        }

        return $data;
    }

    public function key(): string
    {
        return 'profile';
    }

    public function priority(): int
    {
        return 0;
    }
}
