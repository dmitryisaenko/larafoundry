<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\ActivityLog\Contracts;

/**
 * Resolves an approximate country/city from an IP address.
 *
 * The core ships a default backed by ip-api.com ({@see
 * \Dmitryisaenko\LaraFoundry\ActivityLog\Geo\IpApiGeoResolver}). A host that
 * wants a different provider (or an offline GeoIP database) binds its own
 * implementation to this contract via config `larafoundry-activitylog.geo.resolver`.
 */
interface GeoResolver
{
    /**
     * @return array{country: string, city: string}
     */
    public function resolve(string $ip): array;
}
