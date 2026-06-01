<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces authenticated users to verify their email before reaching the app.
 *
 * Guests pass through. Users already verified (or whose model does not
 * implement MustVerifyEmail) pass through. A configurable allow-list of route
 * names and path prefixes stays reachable so users can verify, log out, manage
 * their profile, etc.
 *
 * Host-specific bypasses (admin override, impersonation) are not baked in:
 * the core knows nothing of roles or the Impersonate package. A host that
 * needs them registers its own middleware ahead of this one, or overrides
 * {@see EnsureEmailIsVerified::shouldBypass()}.
 *
 * Config: `larafoundry.security.email_verification`.
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if (! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        if ($this->shouldBypass($request)) {
            return $next($request);
        }

        if ($this->isExcluded($request)) {
            return $next($request);
        }

        $route = config('larafoundry.security.email_verification.redirect_route', 'verification.notice');

        return redirect()->route($route);
    }

    /**
     * Hook for host apps to grant an unconditional pass (e.g. admins,
     * impersonation). Returns false in the core.
     */
    protected function shouldBypass(Request $request): bool
    {
        return false;
    }

    protected function isExcluded(Request $request): bool
    {
        $routeName = $request->route()?->getName();

        $routes = (array) config('larafoundry.security.email_verification.except_routes', []);

        foreach ($routes as $pattern) {
            if ($routeName !== null && fnmatch($pattern, $routeName)) {
                return true;
            }
        }

        $path = $request->path();

        $prefixes = (array) config('larafoundry.security.email_verification.except_prefixes', []);

        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
