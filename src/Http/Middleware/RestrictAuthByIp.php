<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route group to an IP allow-list in production.
 *
 * Intended for the admin / auth zone of a single-operator deployment. Outside
 * production, or when no allow-list is configured, every request passes.
 * Configure the list via `larafoundry.security.admin_ips` (CSV or array).
 */
class RestrictAuthByIp
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->isProduction()) {
            return $next($request);
        }

        $allowed = $this->allowedIps();

        if ($allowed === []) {
            return $next($request);
        }

        if (! in_array($request->ip(), $allowed, true)) {
            abort(403, __('Access restricted.'));
        }

        return $next($request);
    }

    /**
     * @return array<int, string>
     */
    protected function allowedIps(): array
    {
        $configured = config('larafoundry.security.admin_ips', '');

        if (is_array($configured)) {
            $list = $configured;
        } else {
            $list = explode(',', (string) $configured);
        }

        return array_values(array_filter(array_map('trim', $list), static fn (string $ip): bool => $ip !== ''));
    }
}
