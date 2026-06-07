<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Legal\Http\Middleware;

use Closure;
use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Dmitryisaenko\LaraFoundry\Http\Concerns\DetectsHardFailureClients;
use Dmitryisaenko\LaraFoundry\Http\Middleware\EnsureAdminOtpVerified;
use Dmitryisaenko\LaraFoundry\Legal\Support\ConsentManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Terms re-acceptance gate (phase 5.3 part B).
 *
 * Forces an authenticated user whose accepted Terms version is stale onto the
 * accept screen before they can use the app. Registered under the alias
 * `larafoundry.terms`; a host applies it to its authenticated web group.
 *
 * It passes through in every case where enforcement would be wrong:
 *  - guests (nothing to enforce yet);
 *  - enforcement off, or no Terms page published (FAIL-OPEN — the published
 *    version is null, so users are never trapped behind a missing page);
 *  - the platform super-admin (exempt from their own terms);
 *  - the user is already up to date;
 *  - the request targets a bypass route/prefix (logout, the accept screen, the
 *    cookie endpoint, public legal pages, email verification, plus host-configured
 *    extras) — so the user can always read, accept, verify or leave.
 *
 * A JSON/XHR client that cannot follow the HTML redirect gets a 403 instead of a
 * 302 (mirrors {@see EnsureAdminOtpVerified}).
 */
class EnsureTermsAccepted
{
    use DetectsHardFailureClients;

    public function __construct(
        protected ConsentManager $consent,
        protected VisitorStatus $visitorStatus,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        // Fail-open kill-switch: enforcement off or nothing published.
        if (! $this->consent->termsRequired()) {
            return $next($request);
        }

        // The operator is not gated by the terms they themselves publish.
        if ($this->visitorStatus->isAdmin($user)) {
            return $next($request);
        }

        if (! $this->consent->needsTermsAcceptance($user)) {
            return $next($request);
        }

        if ($this->isExcluded($request)) {
            return $next($request);
        }

        if ($this->expectsHardFailure($request)) {
            abort(403);
        }

        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route('larafoundry.consent.terms.show');
    }

    /**
     * Whether the request targets a route/prefix that must stay reachable while
     * the gate is active. The built-in list keeps the accept/logout/verify flows
     * open; a host extends it via config.
     */
    protected function isExcluded(Request $request): bool
    {
        $routeName = $request->route()?->getName();

        $routes = array_merge([
            'larafoundry.consent.terms.show',
            'larafoundry.consent.terms.accept',
            'larafoundry.consent.cookies',
            'larafoundry.legal.show',
            'logout',
            'verification.*',
        ], (array) config('larafoundry-legal.terms.except_routes', []));

        foreach ($routes as $pattern) {
            if ($routeName !== null && fnmatch($pattern, $routeName)) {
                return true;
            }
        }

        $path = $request->path();

        $prefixes = array_merge([
            'legal',
            'terms/accept',
        ], (array) config('larafoundry-legal.terms.except_prefixes', []));

        foreach ($prefixes as $prefix) {
            // Segment-aware: `legal` matches `legal` and `legal/cookies` but NOT a
            // host path like `legal-documents`, so the bypass list cannot be
            // widened by an accidental prefix collision.
            $prefix = rtrim((string) $prefix, '/');

            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
