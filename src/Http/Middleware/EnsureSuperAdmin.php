<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Http\Middleware;

use Closure;
use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the platform operator console to super-admins (phase 2.1).
 *
 * This is the FIRST admin-surface gate: the activity log (and every operator
 * section that follows) sits behind it. Admin status is decided by the core's
 * phase-1.1 {@see VisitorStatus} — the `is_admin` flag plus, when configured, an
 * email allow-list — NOT a self-rolled check. An optional IP allow-list
 * (`larafoundry.security.admin_ips`) adds a network-level fence on top.
 *
 * Registered under the alias `larafoundry.admin`.
 */
class EnsureSuperAdmin
{
    public function __construct(
        protected VisitorStatus $visitorStatus,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->visitorStatus->isAdmin($user)) {
            abort(403);
        }

        if (! $this->ipAllowed($request)) {
            abort(403);
        }

        return $next($request);
    }

    /**
     * When an admin IP allow-list is configured, the request IP must be on it.
     * An empty/unset list means "no IP fence" (flag/email gate still applies).
     *
     * The config accepts a CSV string or an array; both are normalised to a
     * list of non-empty IPs. (A bare `(array) ''` would yield `['']`, fencing
     * out every request — so empties are stripped.)
     */
    protected function ipAllowed(Request $request): bool
    {
        $raw = config('larafoundry.security.admin_ips', []);

        $allowed = array_filter(array_map(
            'trim',
            is_array($raw) ? $raw : explode(',', (string) $raw)
        ), fn ($ip) => $ip !== '');

        if ($allowed === []) {
            return true;
        }

        return in_array($request->ip(), $allowed, true);
    }
}
