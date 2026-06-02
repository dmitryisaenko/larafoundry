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

        if (method_exists($user, 'getActiveCompany') && $user->getActiveCompany() !== null) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, __('larafoundry::tenancy.no_active_company'));
        }

        return redirect()->route('tenancy.companies.create')
            ->with('error', __('larafoundry::tenancy.no_active_company'));
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
