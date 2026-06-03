<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\ActivityLog\Geo;

use Dmitryisaenko\LaraFoundry\ActivityLog\Contracts\GeoResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Default geo resolver: ip-api.com, cached, fail-soft (phase 2.1).
 *
 * Privacy: private / loopback IPs are answered locally and NEVER leave the
 * server. Public IPs are looked up once per TTL and cached. Any failure
 * (timeout, non-2xx, exception) degrades to "Unknown" rather than throwing, so
 * geo enrichment can never break the request or the queue worker.
 */
class IpApiGeoResolver implements GeoResolver
{
    /**
     * @return array{country: string, city: string}
     */
    public function resolve(string $ip): array
    {
        if ($ip === '' || $this->isPrivateIp($ip)) {
            return [
                'country' => __('larafoundry::activitylog.geo.local'),
                'city' => __('larafoundry::activitylog.geo.local'),
            ];
        }

        $ttl = (int) config('larafoundry-activitylog.geo.cache_ttl', 24 * 60 * 60);
        $timeout = (int) config('larafoundry-activitylog.geo.timeout', 5);

        return Cache::remember("larafoundry.geo.{$ip}", $ttl, function () use ($ip, $timeout) {
            try {
                $response = Http::timeout($timeout)->get("http://ip-api.com/json/{$ip}");

                if ($response->successful()) {
                    return [
                        'country' => (string) ($response->json('country') ?: __('larafoundry::activitylog.geo.unknown')),
                        'city' => (string) ($response->json('city') ?: __('larafoundry::activitylog.geo.unknown')),
                    ];
                }
            } catch (Throwable $e) {
                Log::warning("LaraFoundry geo lookup failed for {$ip}: ".$e->getMessage());
            }

            return $this->unknown();
        });
    }

    /**
     * Loopback and RFC1918 / link-local / unique-local ranges, plus anything
     * PHP itself classifies as non-public. Uses the native filter rather than
     * string prefixes so it also catches IPv6 private space (fc00::/7, fe80::).
     */
    protected function isPrivateIp(string $ip): bool
    {
        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            return true;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * @return array{country: string, city: string}
     */
    protected function unknown(): array
    {
        return [
            'country' => __('larafoundry::activitylog.geo.unknown'),
            'city' => __('larafoundry::activitylog.geo.unknown'),
        ];
    }
}
