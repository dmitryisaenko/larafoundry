<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a value is one of the application's available locales.
 *
 * Guards every entry point where a locale arrives from the outside (profile
 * language switch, query string, API) against junk codes such as 'ua' or
 * 'English' that historically leaked into the data layer. The single source
 * of truth is `larafoundry.locale.available`.
 */
class ValidLocale implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $available = (array) config('larafoundry.locale.available', []);

        if (! is_string($value) || ! in_array($value, $available, true)) {
            $fail('The selected :attribute is not a supported locale.')->translate();
        }
    }
}
