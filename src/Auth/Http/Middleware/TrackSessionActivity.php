<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Http\Middleware;

use Closure;
use Dmitryisaenko\LaraFoundry\Auth\Contracts\DeviceFingerprintResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records and refreshes the tracked session row on every authenticated request.
 *
 * This is the single capture point for session tracking, deliberately a
 * per-request middleware rather than a `Login`-event listener: the framework
 * regenerates the session id multiple times during the Fortify login pipeline,
 * so anything keyed to the id at `Login` time goes stale. Running per request,
 * we always see the FINAL, live session id.
 *
 * Behaviour, mirroring the legacy donor's UpdateLastSessionActivity:
 *   - first authenticated request on a new session id → creates the row, with
 *     device fingerprint and login method (read once from a session hint the
 *     OAuth flow may set, else 'native');
 *   - subsequent requests → refresh last_activity (+ last_route_name for GETs)
 *     and the user's last_activity_at.
 *
 * Because the row is (re)created for any live session, "log out other devices"
 * relies on actually killing the framework session — handled immediately on the
 * database driver by SessionController, and naturally on other drivers because
 * a destroyed session never reaches this middleware again.
 *
 * Register on the authenticated route group, after the session middleware.
 * No-op for guests.
 */
class TrackSessionActivity
{
    /**
     * Session key the OAuth callback uses to pass the provider name through.
     */
    public const METHOD_HINT = 'larafoundry.login_method';

    public function __construct(
        protected DeviceFingerprintResolver $devices,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        // The PIN routes must not refresh last_activity: entering (or being
        // bounced to) the PIN screen is not activity, or the idle timer that
        // CheckPinLock reads would never elapse.
        if ($user !== null
            && method_exists($user, 'recordSessionActivity')
            && $request->hasSession()
            && ! Str::is('pin.*', (string) $request->route()?->getName())) {
            $user->recordSessionActivity(
                sessionId: $request->session()->getId(),
                device: $this->devices->resolve($request),
                loginMethodHint: $request->session()->pull(self::METHOD_HINT),
                routeName: $this->currentRouteName($request),
            );
        }

        return $response;
    }

    protected function currentRouteName(Request $request): ?string
    {
        // Only meaningful for non-XHR GETs — the "last page the user was on".
        if (! $request->isMethod('GET') || $request->expectsJson()) {
            return null;
        }

        return $request->route()?->getName();
    }
}
