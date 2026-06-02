<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures an authenticated user has an active company selected (teams mode).
 *
 * If one is already set and still valid it is left alone; otherwise the user's
 * first owned company (then any membership) is promoted to active. With no
 * companies at all it is a no-op — the user is on their way to the creation flow.
 *
 * Requires a verified email before binding a company, mirroring the donor: an
 * unverified user shouldn't be acting on behalf of a tenant yet.
 *
 * Register on the authenticated web group AFTER `larafoundry.session.track`
 * (TrackSessionActivity), so the `user_sessions` row the active company is
 * written onto already exists. No-op for guests and in personal mode.
 *
 * Alias: `larafoundry.tenant.set`.
 */
class SetActiveTenant
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

        if ($user === null || ! $this->canResolveCompanies($user)) {
            return $next($request);
        }

        // Business features (and thus a tenant) require a verified email.
        if (method_exists($user, 'hasVerifiedEmail') && ! $user->hasVerifiedEmail()) {
            return $next($request);
        }

        $active = $user->getActiveCompany();

        if ($active !== null) {
            // Already set and resolvable (the resolver only returns companies the
            // user still belongs to), so trust it.
            return $next($request);
        }

        // Nothing valid set — promote the next available company, if any.
        $user->setNextAvailableCompany();

        return $next($request);
    }

    protected function canResolveCompanies(object $user): bool
    {
        return method_exists($user, 'getActiveCompany')
            && method_exists($user, 'setNextAvailableCompany');
    }
}
