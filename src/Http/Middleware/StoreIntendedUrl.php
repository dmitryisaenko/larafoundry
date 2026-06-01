<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records the current URL as the "intended" location for post-login redirects.
 *
 * Only full-page Inertia GET/POST visits are stored — JSON endpoints, polling
 * routes and similar should never become a redirect target. Host apps list
 * those route names under `larafoundry.inertia.intended_url.except_routes`.
 */
class StoreIntendedUrl
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldStore($request)) {
            Redirect::setIntendedUrl($request->fullUrl());
        }

        return $next($request);
    }

    protected function shouldStore(Request $request): bool
    {
        if (! in_array($request->method(), ['GET', 'POST'], true)) {
            return false;
        }

        if (! $request->inertia()) {
            return false;
        }

        $routeName = $request->route()?->getName();

        foreach ((array) config('larafoundry.inertia.intended_url.except_routes', []) as $pattern) {
            if ($routeName !== null && fnmatch($pattern, $routeName)) {
                return false;
            }
        }

        return true;
    }
}
