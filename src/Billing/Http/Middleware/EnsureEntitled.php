<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Http\Middleware;

use Closure;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\EntitlementResolver;
use Dmitryisaenko\LaraFoundry\Billing\Support\BillingSubject;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route behind a plan entitlement (sub-phase B6) — the host-facing half of
 * the Billing/RBAC loop. Alias: `entitlement` (used as `entitlement:<feature>`).
 *
 * Decision D-B6-1: this is route middleware, NOT a user Gate ability. Entitlement is a
 * property of the TENANT's plan, not the user, so binding it to Laravel's Gate (which
 * is user-centric and short-circuits super-admins via Gate::before) would be the wrong
 * axis. Keeping it a middleware also means a super-admin impersonating a tenant is
 * correctly subject to THAT tenant's plan, with no bypass leak.
 *
 * The "who may" axis stays RBAC's job: a host typically layers both, e.g.
 * `->middleware(['can:production.view', 'entitlement:production.module'])`.
 */
final class EnsureEntitled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        // Billing off: do not gate anything (a FREE host stays fully usable). Mirrors
        // the resolver's own open-when-disabled branch, short-circuited here so the
        // tenant lookup is skipped entirely.
        if (! (bool) config('larafoundry.billing.enabled', false)) {
            return $next($request);
        }

        $tenant = $this->resolveTenant($request);

        if ($tenant !== null && app(EntitlementResolver::class)->allows($tenant, $feature)) {
            return $next($request);
        }

        return $this->deny($request);
    }

    /**
     * The active tenant for the request, resolved SERVER-SIDE from the authenticated
     * user (never from request input): its active company in `teams` mode, the user
     * itself in `personal`. Null when there is no user or no subject, which the caller
     * treats as not-entitled (fail-closed).
     */
    protected function resolveTenant(Request $request): ?Tenant
    {
        return BillingSubject::tenant(BillingSubject::for($request->user()));
    }

    /**
     * Refuse an unentitled request: a JSON caller gets a 403, a browser is redirected
     * to the configured upgrade route when one is set (and exists), otherwise also a
     * 403 (the upgrade/pricing page itself arrives in B7). The message is generic so it
     * never reveals which features the plan does or does not carry.
     */
    protected function deny(Request $request): Response
    {
        $message = __('larafoundry::billing.entitlement.required');

        if ($request->expectsJson()) {
            abort(403, $message);
        }

        $route = config('larafoundry-billing.entitlements.redirect_route');

        if (is_string($route) && $route !== '' && app('router')->has($route)) {
            $redirect = redirect()->route($route);

            // Flash the reason only when the request has a session (the upgrade route is
            // expected to be web/session-backed). A host that attaches this middleware
            // to a sessionless route still gets a clean redirect, not a flash-on-null
            // crash.
            return $request->hasSession()
                ? $redirect->with('error', $message)
                : $redirect;
        }

        abort(403, $message);
    }
}
