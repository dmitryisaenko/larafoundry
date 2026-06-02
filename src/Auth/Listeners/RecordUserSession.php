<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Listeners;

use Dmitryisaenko\LaraFoundry\Auth\Contracts\DeviceFingerprintResolver;
use Illuminate\Auth\Events\Login;

/**
 * Records a tracked UserSession on every authenticated login.
 *
 * Subscribed to Laravel's `Login` event, which fires for ALL entry paths —
 * native Fortify login, registration auto-login, OAuth (`Auth::login` in the
 * callback), remember-me re-auth, 2FA challenge completion. This is the single
 * capture point for session tracking, replacing the previous split between a
 * Fortify pipeline step (login only) and a hand-written copy in the OAuth
 * controller (which left registration untracked).
 *
 * Laravel regenerates the session id before firing `Login` in the stateful
 * guard, so the row is keyed to the post-login id — no session-fixation window.
 *
 * The login method (native vs a provider name) is read from a short-lived
 * session hint the OAuth flow sets; absent the hint it defaults to 'native'.
 */
class RecordUserSession
{
    /**
     * Session key the OAuth callback uses to tell us which provider authenticated.
     */
    public const METHOD_HINT = 'larafoundry.login_method';

    public function __construct(
        protected DeviceFingerprintResolver $devices,
    ) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! method_exists($user, 'recordSession')) {
            return;
        }

        $request = request();
        $session = $request->hasSession() ? $request->session() : null;

        $loginMethod = 'native';
        if ($session !== null && $session->has(self::METHOD_HINT)) {
            $loginMethod = (string) $session->pull(self::METHOD_HINT);
        }

        $user->recordSession(
            sessionId: $session?->getId() ?? '',
            device: $this->devices->resolve($request),
            loginMethod: $loginMethod,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }
}
