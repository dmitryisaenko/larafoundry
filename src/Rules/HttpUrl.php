<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a value is a well-formed URL whose scheme is http or https.
 *
 * Laravel's built-in `url` rule accepts ANY scheme filter_var recognises, which
 * includes `javascript:`, `data:`, `mailto:` and friends. When a stored URL is
 * later rendered into an `href`, a `javascript:`/`data:` scheme is a stored-XSS
 * vector — so anywhere a URL is persisted for later linking (social links here),
 * the scheme must be locked to http(s). This rule is the single, testable place
 * that enforces it, rather than an inline closure repeated per field.
 */
class HttpUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute must be a valid URL.')->translate();

            return;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        // Compare case-insensitively: `JavaScript:` and `HTTPS:` are equivalent
        // schemes to a browser, so the guard must not be bypassable by casing.
        $scheme = is_string($scheme) ? strtolower($scheme) : null;

        if (! in_array($scheme, ['http', 'https'], true)) {
            $fail('The :attribute must start with http:// or https://.')->translate();
        }
    }
}
