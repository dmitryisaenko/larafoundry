<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Contracts;

/**
 * Resolves a locale from a client IP address.
 *
 * The core ships no implementation: geo-IP lookups mean either an external
 * HTTP call (slow, leaks the IP, a point of failure) or a bundled GeoIP
 * database (heavy). A host that wants this binds its own implementation and
 * enables it via `larafoundry.locale.geoip`.
 */
interface LocaleGeoResolver
{
    /**
     * Best-effort locale for the given IP, or null when undetermined.
     *
     * @param  array<int, string>  $available  whitelist of allowed locales
     */
    public function resolve(string $ip, array $available): ?string;
}
