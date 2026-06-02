<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Http\Middleware;

use Closure;
use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces that the authenticated account is not blocked or soft-deleted.
 *
 * The trait exposes `isBlocked()`/`isDeleted()` and {@see VisitorStatus} can
 * report those states, but a predicate enforces nothing on its own. This
 * middleware is the consumer that makes the control real: on every request it
 * resolves the visitor status and, for a blocked or deleted account, logs the
 * user out and sends them to the blocked screen (XHR/Inertia get a 403).
 *
 * Register it on the authenticated route group. It is a no-op for guests and
 * for healthy accounts, so it is safe to apply broadly.
 */
class EnsureAccountIsActive
{
    public function __construct(
        protected VisitorStatus $status,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $status = $this->status->for($user);

        if ($status === VisitorStatus::BLOCKED || $status === VisitorStatus::DELETED) {
            return $this->reject($request, $status);
        }

        return $next($request);
    }

    protected function reject(Request $request, string $status): Response
    {
        Auth::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            abort(403, __('larafoundry::auth.account.'.$status));
        }

        $route = config('larafoundry.auth.blocked_redirect_route');

        if (is_string($route) && app('router')->has($route)) {
            return redirect()->route($route);
        }

        return redirect('/')->with('error', __('larafoundry::auth.account.'.$status));
    }
}
