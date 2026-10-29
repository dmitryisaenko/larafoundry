<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Http\Middleware;

use Closure;
use Dmitryisaenko\LaraFoundry\Billing\Actions\GetBlockStatusAction;
use Dmitryisaenko\LaraFoundry\Billing\Support\BillingSubject;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces billing access on a tenant route (sub-phase B7a). Alias: `billing.access`.
 *
 * The add-on's half of the access seam, layered AFTER the core's middleware in a host's
 * tenant group:
 *   - `larafoundry.account.active` (core) blocks a user whose OWN account is banned;
 *   - `larafoundry.tenant.required` (core) blocks a super-admin-blocked company;
 *   - `billing.access` (this) blocks on SUBSCRIPTION state — never duplicating the two
 *     core checks.
 *
 * Two outcomes for an unpaid company, by `enforcement.mode`:
 *   - soft (default): pass through; the frontend shows an alert from GetBlockStatusAction.
 *   - hard: redirect to the blocked page (browser) or a 403 (JSON).
 * Owner-ban is a hard cascade in BOTH modes: a banned payer freezes the whole company.
 *
 * Short-circuits when billing is off (a FREE host is never gated), when there is no
 * authenticated user or paying subject (the auth / tenancy middleware own those), for a
 * company still in setup, and for the exempt escape-hatch routes (the blocked page, the
 * pricing/checkout flow, logout, company switch) so it can never loop.
 *
 * The subject is resolved through {@see BillingSubject}, so the same middleware enforces
 * a company's subscription in `teams` mode and a person's own subscription in
 * `personal` — where there is no active company to read at all.
 */
final class CheckAccessMiddleware
{
    public function __construct(private readonly GetBlockStatusAction $blockStatus) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('larafoundry.billing.enabled', false)) {
            return $next($request);
        }

        $user = $request->user();
        $company = BillingSubject::for($user);

        if ($user === null || $company === null) {
            return $next($request);
        }

        if (method_exists($company, 'isInSetup') && $company->isInSetup()) {
            return $next($request);
        }

        // Exempt routes bypass the FULL check (subscription AND owner-ban) so the escape
        // hatches — blocked page, checkout flow, logout, company switch — never loop.
        if ($this->isExempt($request)) {
            return $next($request);
        }

        $status = ($this->blockStatus)($company);

        // Owner-ban: a hard cascade in every mode.
        if ($status['company_block_reason'] === 'owner_banned') {
            return $this->blocked($request, 'owner_banned');
        }

        if ($status['company_blocked']) {
            // Soft mode lets the request through; the frontend surfaces the alert.
            if ($this->mode() !== 'hard') {
                return $next($request);
            }

            $type = $this->isOwner($user, $company) ? 'payment_required' : 'payment_required_non_owner';

            return $this->blocked($request, $type);
        }

        return $next($request);
    }

    protected function mode(): string
    {
        // Case-insensitive so `Hard`/`HARD` don't silently degrade to soft.
        return strtolower(trim((string) config('larafoundry-billing.enforcement.mode', 'soft')));
    }

    /**
     * Whether the current user is the subject's owner of record, which decides the
     * blocked-page message. Compares ids rather than the owners() pivot so it does not
     * depend on the host's model class names.
     *
     * In `personal` mode the payer IS the signed-in user, so there is never a
     * "someone else has to pay" case — always the owner message.
     */
    protected function isOwner(object $user, object $company): bool
    {
        if (BillingSubject::isPersonal()) {
            return true;
        }

        $createdBy = method_exists($company, 'getAttribute') ? $company->getAttribute('created_by_id') : null;

        return $createdBy !== null && (string) $user->getAuthIdentifier() === (string) $createdBy;
    }

    protected function isExempt(Request $request): bool
    {
        foreach ($this->exemptPatterns() as $pattern) {
            if ($request->routeIs($pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    protected function exemptPatterns(): array
    {
        $patterns = [];

        // The blocked page and the upgrade/pricing page are exempt automatically — a
        // blocked user must be able to reach the page that lets them pay.
        foreach ([
            config('larafoundry-billing.enforcement.blocked_route'),
            config('larafoundry-billing.entitlements.redirect_route'),
        ] as $route) {
            if (is_string($route) && $route !== '') {
                $patterns[] = $route;
            }
        }

        foreach ((array) config('larafoundry-billing.enforcement.exempt_routes', []) as $pattern) {
            if (is_string($pattern) && $pattern !== '') {
                $patterns[] = $pattern;
            }
        }

        return $patterns;
    }

    protected function blocked(Request $request, string $type): Response
    {
        $route = config('larafoundry-billing.enforcement.blocked_route');
        $hasRoute = is_string($route) && $route !== '' && app('router')->has($route);

        // An Inertia XHR navigation (X-Inertia header) treated as JSON would surface the
        // blocked page as a 403 error modal. Reply with the Inertia location protocol — a
        // 409 carrying X-Inertia-Location — so the client does a full browser visit to the
        // blocked page (sub-phase B7b cron-polish — A2 LOW). Built directly rather than via
        // Inertia::location() so it never depends on the request `inertia()` macro.
        if ($request->header('X-Inertia') && $hasRoute) {
            return response('', 409, ['X-Inertia-Location' => route($route, ['type' => $type])]);
        }

        // A true API/JSON client (no Inertia header) gets a 403.
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403, 'company_blocked');
        }

        if ($hasRoute) {
            return redirect()->route($route, ['type' => $type]);
        }

        abort(403, 'company_blocked');
    }
}
