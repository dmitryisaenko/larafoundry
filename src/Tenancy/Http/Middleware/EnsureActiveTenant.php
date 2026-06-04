<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates company-management routes behind having an active company (teams mode).
 *
 * Company screens (dashboard, employees, settings) are meaningless without a
 * selected company, so this hard-requires one and redirects/aborts when absent.
 * A short allow-list of routes stays reachable without one (e.g. a member asking
 * to remove themselves) — configured via `tenancy.routes_without_active_tenant`.
 *
 * This is also where a super-admin company block cascades (phase 3.3): when the
 * active company is blocked, every member is denied the tenant screens here —
 * one column on the company takes the whole team offline, regardless of role.
 * The block is enforced at this single tenancy boundary rather than scattered
 * across controllers, so there is no screen a blocked company's member can reach.
 *
 * Always passes in personal mode (the user is always their own tenant).
 *
 * Alias: `larafoundry.tenant.required`.
 */
class EnsureActiveTenant
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('larafoundry.tenancy.mode') === 'personal') {
            return $next($request);
        }

        $user = $request->user();

        if ($user === null || $this->isExempt($request)) {
            return $next($request);
        }

        $company = method_exists($user, 'getActiveCompany') ? $user->getActiveCompany() : null;

        if ($company !== null && $this->isBlocked($company)) {
            return $this->handleBlocked($request, $user);
        }

        if ($company !== null) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, __('larafoundry::tenancy.no_active_company'));
        }

        return redirect()->route('tenancy.companies.create')
            ->with('error', __('larafoundry::tenancy.no_active_company'));
    }

    /**
     * Whether the active company has been blocked by a super-admin (phase 3.3).
     */
    protected function isBlocked(object $company): bool
    {
        return method_exists($company, 'isBlocked') && $company->isBlocked();
    }

    /**
     * Handle a member whose active company is blocked (phase 3.3).
     *
     * Self-healing and loop-safe. First try to move the member to one of their
     * OTHER, unblocked companies (setNextAvailableCompany skips blocked ones): a
     * multi-company member lands on a working company on the same request instead
     * of seeing a dead end. If they have no unblocked company left, the active
     * company is now cleared, so the redirect to the blocked screen cannot loop —
     * even if the host gated that screen with this same middleware, it would now
     * see "no active company" and fall through to the create flow rather than
     * re-detecting the block. We deliberately do NOT log the user out (unlike a
     * blocked ACCOUNT): the member may still own healthy companies.
     */
    protected function handleBlocked(Request $request, object $user): Response
    {
        if (method_exists($user, 'setNextAvailableCompany') && $user->setNextAvailableCompany()) {
            // Landed on another, unblocked company — replay the original request.
            return redirect($request->fullUrl());
        }

        if ($request->expectsJson()) {
            abort(403, __('larafoundry::tenancy.company_blocked'));
        }

        $route = config('larafoundry.auth.blocked_redirect_route');

        if (is_string($route) && app('router')->has($route)) {
            return redirect()->route($route)
                ->with('error', __('larafoundry::tenancy.company_blocked'));
        }

        return redirect()->route('tenancy.companies.create')
            ->with('error', __('larafoundry::tenancy.company_blocked'));
    }

    /**
     * Whether the current route is allowed without an active company.
     */
    protected function isExempt(Request $request): bool
    {
        $routeName = $request->route()?->getName();

        if ($routeName === null) {
            return false;
        }

        foreach ((array) config('larafoundry.tenancy.routes_without_active_tenant', []) as $pattern) {
            if (Str::is($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }
}
