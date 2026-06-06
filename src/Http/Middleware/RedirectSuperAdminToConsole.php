<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Http\Middleware;

use Closure;
use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Dmitryisaenko\LaraFoundry\Http\Concerns\DetectsHardFailureClients;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confines the platform super-admin to the operator console (phase 1.4).
 *
 * The super-admin identity is deliberately separate from tenant identities — it
 * cannot own a company (enforced in CreateCompanyAction). This middleware
 * enforces the other half of that separation: a super-admin who lands on a
 * tenant-facing route is redirected back into the console. Non-admins fall
 * straight through, so the host applies it to its whole web group without having
 * to single out tenant routes.
 *
 * The routes a super-admin still needs — the console itself, the OTP step-up
 * gate, the PIN screens, logout, password-confirm — are matched against the
 * configured allow-list and let through, so there is no redirect loop.
 *
 * Registered under the alias `larafoundry.confine_admin`.
 */
class RedirectSuperAdminToConsole
{
    use DetectsHardFailureClients;

    public function __construct(
        protected VisitorStatus $visitorStatus,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->visitorStatus->isAdmin($user)) {
            return $next($request);
        }

        if ($this->isAllowed($request)) {
            return $next($request);
        }

        // A pure JSON/XHR client cannot follow an HTML redirect, so answer it
        // with a 403 instead of a 302 to the console. Inertia requests (which
        // carry X-Inertia) DO follow the redirect, so they keep the 302.
        if ($this->expectsHardFailure($request)) {
            abort(403);
        }

        return redirect()->route($this->consoleRoute());
    }

    /**
     * Whether the super-admin may stay on this route (vs. being bounced to the
     * console). Matching is by route name against the configured fnmatch
     * patterns. The console target itself is always allowed (so a host that
     * points console_route outside allowed_routes cannot create a redirect
     * loop). An unnamed route can't match a pattern, so console-prefixed paths
     * are allowed by path as a safety net — the super-admin is never bounced off
     * the console itself.
     */
    protected function isAllowed(Request $request): bool
    {
        $routeName = $request->route()?->getName();

        if ($routeName === null) {
            return $request->is('admin', 'admin/*');
        }

        if ($routeName === $this->consoleRoute()) {
            return true;
        }

        foreach ((array) config('larafoundry.security.super_admin.allowed_routes', []) as $pattern) {
            if (Str::is($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }

    protected function consoleRoute(): string
    {
        return (string) config('larafoundry.security.super_admin.console_route', 'admin.dashboard.index');
    }
}
