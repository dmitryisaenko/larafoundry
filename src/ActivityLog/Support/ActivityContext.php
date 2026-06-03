<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\ActivityLog\Support;

use Dmitryisaenko\LaraFoundry\Auth\Contracts\DeviceFingerprintResolver;
use Illuminate\Http\Request;

/**
 * Collects the request-side context for an activity entry, in one place
 * (phase 2.1).
 *
 * Device is resolved through the core's phase-1.1 {@see
 * DeviceFingerprintResolver} contract — the single source of device data — NOT
 * a second user-agent parser (the donor reached for Jenssegers\Agent directly).
 *
 * Two security responsibilities live here (decision D2.1-h):
 *  - `full_url` has its query-string PII redacted before storage;
 *  - arbitrary `properties` are redacted by key before storage.
 * Matching is case-insensitive against `larafoundry-activitylog.pii_redact_keys`.
 */
class ActivityContext
{
    public function __construct(
        protected DeviceFingerprintResolver $deviceResolver,
    ) {}

    /**
     * The HTTP/device columns for the current request.
     *
     * Safe to call outside an HTTP request (queue/console): falls back to nulls
     * where no request context exists.
     *
     * @return array{
     *     user_agent: ?string,
     *     user_ip: ?string,
     *     user_device_type: ?string,
     *     user_device_name: ?string,
     *     user_os: ?string,
     *     user_browser: ?string,
     *     route_name: ?string,
     *     request_method: ?string,
     *     full_url: ?string,
     * }
     */
    public function forRequest(?Request $request = null): array
    {
        $request ??= request();

        $device = $this->deviceResolver->resolve($request);

        return [
            'user_agent' => $request->userAgent(),
            'user_ip' => $request->ip(),
            'user_device_type' => $device->type,
            'user_device_name' => $device->name,
            'user_os' => $device->os,
            'user_browser' => $device->browser,
            'route_name' => $request->route()?->getName(),
            'request_method' => $request->getMethod(),
            'full_url' => $this->redactUrl($request->fullUrl()),
        ];
    }

    /**
     * Mask PII query-string values in a URL, byte-for-byte preserving everything
     * else.
     *
     * A reset token or 2FA code in a query string would otherwise be stored
     * verbatim; matching keys have their value replaced with `[redacted]`. The
     * rest of the URL is left untouched — we operate directly on the original
     * query string rather than parse_str()/http_build_query(), which would mangle
     * dotted/spaced keys (`filter.name` -> `filter_name`), re-index array params
     * (`ids[]` -> `ids[0]`), drop repeated scalar keys, and corrupt relative URLs
     * into `http:///...`.
     */
    public function redactUrl(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if ($query === null || $query === false || $query === '') {
            return $url;
        }

        $keys = array_map(
            'strtolower',
            (array) config('larafoundry-activitylog.pii_redact_keys', [])
        );

        $redacted = preg_replace_callback(
            '/(^|&)([^=&]+)=([^&]*)/',
            function (array $m) use ($keys): string {
                $name = strtolower(urldecode($m[2]));

                return in_array($name, $keys, true)
                    ? $m[1].$m[2].'=[redacted]'
                    : $m[0];
            },
            $query
        );

        // Replace only the query segment, leaving scheme/host/path/fragment as-is
        // (so a relative URL stays relative).
        return str_replace('?'.$query, '?'.$redacted, $url);
    }

    /**
     * Recursively mask values whose key matches a configured PII key.
     *
     * @param  array<mixed>  $properties
     * @return array<mixed>
     */
    public function redactProperties(array $properties): array
    {
        $keys = array_map(
            'strtolower',
            (array) config('larafoundry-activitylog.pii_redact_keys', [])
        );

        foreach ($properties as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $keys, true)) {
                $properties[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $properties[$key] = $this->redactProperties($value);
            }
        }

        return $properties;
    }
}
