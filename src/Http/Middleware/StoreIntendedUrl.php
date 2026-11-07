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
 * Only Inertia GET visits are stored — JSON endpoints, polling
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

    /**
     * ⚠️ The core's own lock and gate screens are never a place to come back to: the PIN screen and
     * the Terms screen send the user ON to the intended URL, so storing their own address would
     * overwrite where the user was really headed and bring them back to the screen they just left.
     */
    private const OWN_SCREENS = ['pin.*', 'larafoundry.consent.*'];

    protected function shouldStore(Request $request): bool
    {
        // ⚠️ GET only (the same rule as EnsureTermsAccepted and EnsureAdminOtpVerified, review
        // 2026-11-01): a stored POST is answered by a GET after the redirect, which is a 405 on a
        // form's address.
        if (! $request->isMethod('GET')) {
            return false;
        }

        if (! $request->inertia()) {
            return false;
        }

        $routeName = $request->route()?->getName();

        foreach ([...self::OWN_SCREENS, ...(array) config('larafoundry.inertia.intended_url.except_routes', [])] as $pattern) {
            if ($routeName !== null && fnmatch($pattern, $routeName)) {
                return false;
            }
        }

        return true;
    }
}
