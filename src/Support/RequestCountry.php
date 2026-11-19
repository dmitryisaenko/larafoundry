<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Support;

use Illuminate\Http\Request;

/**
 * The country a request comes from (v0.58.0), as the proxy in front of the site
 * reports it - Cloudflare writes it into `CF-IPCountry` on every request, with no
 * lookup of ours and nothing sent anywhere.
 *
 * It is where the visitor CONNECTS from, not who they are: a VPN shows the VPN's
 * country, a traveller the country they are in. Fit for choosing what to offer,
 * not for proving anything.
 *
 * ⚠️ Trust the header only behind that proxy. A site reachable directly takes it
 * from whoever sends the request. The header name is
 * `larafoundry.request_country.header`; empty turns this off (always unknown).
 *
 * Unknown is null: no header, `XX` (Cloudflare could not tell), `T1` (Tor), or
 * anything that is not two letters.
 */
final class RequestCountry
{
    /** Cloudflare's codes for "not a country". */
    private const NOT_A_COUNTRY = ['XX', 'T1'];

    /** ISO 3166-1 alpha-2 in upper case, or null when unknown. */
    public function of(Request $request): ?string
    {
        $header = config('larafoundry.request_country.header');

        if (! is_string($header) || trim($header) === '') {
            return null;
        }

        $value = $request->headers->get(trim($header));

        return self::normalise($value);
    }

    /** A two-letter country code in upper case, or null for anything else. */
    public static function normalise(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $code = strtoupper(trim($value));

        if (preg_match('/^[A-Z]{2}$/', $code) !== 1 || in_array($code, self::NOT_A_COUNTRY, true)) {
            return null;
        }

        return $code;
    }
}
