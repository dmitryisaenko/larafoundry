<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Seo\Support;

use Dmitryisaenko\LaraFoundry\Rules\HttpUrl;

/**
 * URL scheme guard for the SEO kit (phase 5.2).
 *
 * The single, testable place that decides whether a value may be rendered into
 * an href / <loc> / og:url. Mirrors the scheme check in {@see HttpUrl}:
 * only http(s) is allowed, so a `javascript:`/`data:` URL can never reach a
 * meta tag or the sitemap (a stored-XSS vector otherwise). Case-insensitive —
 * `HTTPS:` and `JavaScript:` are equivalent schemes to a browser.
 */
final class Url
{
    public static function isHttp(?string $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);
        $scheme = is_string($scheme) ? strtolower($scheme) : null;

        return in_array($scheme, ['http', 'https'], true);
    }
}
