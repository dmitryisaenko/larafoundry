<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\ActivityLog\Http\Middleware;

use Closure;
use Dmitryisaenko\LaraFoundry\ActivityLog\Services\ActivityLogService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs every wrapped request as a route-access entry (phase 2.1).
 *
 * OPT-IN and OFF by default (config `larafoundry-activitylog.route_middleware`):
 * it writes one row per request, which is noisy and adds DB load. Enable it
 * deliberately and exclude polling/high-frequency routes by name (fnmatch).
 *
 * Registered under the alias `larafoundry.activity.route`; the host applies it
 * to whichever route group it wants audited.
 */
class LogActivity
{
    public function __construct(
        protected ActivityLogService $service,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->shouldLog($request)) {
            $this->service->log(
                description: 'route_access',
                logName: 'route',
                properties: ['route' => $request->route()?->getName()],
                isSuccessful: $response->getStatusCode() < 400,
                responseCode: $response->getStatusCode(),
            );
        }

        return $response;
    }

    protected function shouldLog(Request $request): bool
    {
        if (! config('larafoundry-activitylog.route_middleware.enabled', false)) {
            return false;
        }

        $routeName = $request->route()?->getName();

        if ($routeName === null) {
            return true;
        }

        foreach ((array) config('larafoundry-activitylog.route_middleware.excluded', []) as $pattern) {
            if (fnmatch($pattern, $routeName)) {
                return false;
            }
        }

        return true;
    }
}
