<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Http\Middleware;

use Closure;
use Dmitryisaenko\LaraFoundry\Auth\Http\Controllers\SessionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs out a request whose tracked session row has been revoked.
 *
 * This is what makes "log out other devices" effective on ANY session driver.
 * {@see SessionController}
 * deletes the UserSession rows for other devices, but on the file/redis/cookie
 * driver it cannot reach into another device's framework session to kill it.
 * Instead, each request reconciles: if the user is authenticated, uses the
 * tracking trait, yet has no UserSession row for the current session id, the
 * row was revoked elsewhere — so we log this request out.
 *
 * Register it on the authenticated route group, after the session middleware.
 * A grace path skips reconciliation when the user has no rows at all (e.g. the
 * tracking listener has not run yet for a brand-new login in the same request
 * lifecycle).
 */
class ReconcileTrackedSession
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! method_exists($user, 'sessions') || ! $request->hasSession()) {
            return $next($request);
        }

        $sessionId = $request->session()->getId();

        $hasCurrent = $user->sessions()->where('session_id', $sessionId)->exists();

        if (! $hasCurrent && $user->sessions()->exists()) {
            // The user has tracked sessions, but not this one → it was revoked.
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(401);
            }

            return redirect('/');
        }

        return $next($request);
    }
}
