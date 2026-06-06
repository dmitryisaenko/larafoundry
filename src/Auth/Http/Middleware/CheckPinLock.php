<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Http\Middleware;

use Closure;
use Dmitryisaenko\LaraFoundry\Auth\Models\UserSession;
use Dmitryisaenko\LaraFoundry\Http\Concerns\DetectsHardFailureClients;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the session PIN-lock (phase 1.4).
 *
 * For a user who has set a PIN, this middleware auto-locks the current session
 * after it has been idle past `pin.idle_timeout`, then bounces every request to
 * the PIN-entry screen until the PIN is entered. It is a no-op when PIN-lock is
 * disabled, the user has no PIN, or the request is for the PIN routes / logout
 * themselves (otherwise the user could never reach the unlock screen).
 *
 * The host applies it on its web group. The idle timer it reads stays correct
 * regardless of order relative to `larafoundry.session.track`, because the
 * tracker refreshes `last_activity` on the response phase (after $next) while
 * this guard reads it on the request phase (before $next) — and it skips
 * `pin.*` routes so entering the PIN never counts as activity.
 *
 * Registered under the alias `larafoundry.pin`.
 */
class CheckPinLock
{
    use DetectsHardFailureClients;

    /** Route-name patterns always reachable, lock or no lock. */
    protected const ALLOWED = ['pin.*', 'logout', 'password.confirm'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! config('larafoundry.pin.enabled', true)
            || $user === null
            || ! $request->hasSession()
            || ! method_exists($user, 'hasPin')
            || ! $user->hasPin()
            || $this->isAllowed($request)) {
            return $next($request);
        }

        $session = UserSession::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('session_id', $request->session()->getId())
            ->first();

        if ($session === null) {
            return $next($request);
        }

        if (! $session->pin_locked && $this->isIdle($session)) {
            $session->forceFill(['pin_locked' => true])->save();
        }

        if ($session->pin_locked) {
            // A pure JSON/XHR client cannot follow the redirect; tell it the
            // session is locked with 423 (mirrors the gate middlewares' 403).
            if ($this->expectsHardFailure($request)) {
                abort(Response::HTTP_LOCKED);
            }

            return redirect()->route('pin.enter');
        }

        return $next($request);
    }

    protected function isAllowed(Request $request): bool
    {
        $routeName = $request->route()?->getName();

        if ($routeName === null) {
            return false;
        }

        foreach (self::ALLOWED as $pattern) {
            if (Str::is($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }

    protected function isIdle(UserSession $session): bool
    {
        $lastActivity = $session->last_activity;

        if (! $lastActivity instanceof Carbon) {
            return false;
        }

        return $lastActivity->diffInSeconds(now()) >= (int) config('larafoundry.pin.idle_timeout', 1800);
    }
}
