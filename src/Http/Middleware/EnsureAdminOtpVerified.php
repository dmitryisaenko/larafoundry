<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Http\Middleware;

use Closure;
use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Dmitryisaenko\LaraFoundry\Http\Concerns\DetectsHardFailureClients;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The OTP step-up gate for the operator console (phase 1.4).
 *
 * Sits on the admin route group AFTER `larafoundry.admin` (which has already
 * established that the user is the super-admin). It enforces two things, both
 * scoped to the super-admin — every other user passes straight through:
 *
 *  1. ENFORCEMENT — the operator MUST have confirmed per-user 2FA. Without it
 *     they are sent to the host's 2FA-enrolment screen (config
 *     `two_factor_setup_route`); if the host configured none, the gate denies
 *     with 403 (fail closed: no console without 2FA).
 *  2. STEP-UP — even with a valid session, a fresh TOTP code must be entered
 *     once per session before the console opens (a session flag, reset on
 *     login/logout). This is what makes "operator login is OTP-only" hold for
 *     EVERY channel: a password login already triggers Fortify's 2FA challenge,
 *     but an OAuth login bypasses it — this gate catches both uniformly, and it
 *     also defends against a stolen session cookie.
 *
 * The OTP-challenge route itself must NOT carry this middleware, or the redirect
 * to it would loop. Registered under the alias `larafoundry.admin.otp`.
 */
class EnsureAdminOtpVerified
{
    use DetectsHardFailureClients;

    /** Session key set once the super-admin has cleared the step-up this session. */
    public const SESSION_KEY = 'larafoundry_admin_otp_verified';

    public function __construct(
        protected VisitorStatus $visitorStatus,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Only the super-admin is gated; the kill-switch turns it off entirely.
        if (! config('larafoundry.security.super_admin.require_otp', true)
            || $user === null
            || ! $this->visitorStatus->isAdmin($user)) {
            return $next($request);
        }

        // ENFORCEMENT: confirmed 2FA is mandatory for the operator. Fortify's own
        // predicate honours the `confirm` feature (secret + confirmed_at); the
        // operator model always carries TwoFactorAuthenticatable, so the
        // challenge controller relies on it unconditionally too.
        if (! $user->hasEnabledTwoFactorAuthentication()) {
            $setupRoute = config('larafoundry.security.super_admin.two_factor_setup_route');

            if (is_string($setupRoute) && $setupRoute !== '') {
                return redirect()->route($setupRoute)
                    ->with('error', __('larafoundry::auth.admin_otp.setup_required'));
            }

            abort(403, __('larafoundry::auth.admin_otp.setup_required'));
        }

        // STEP-UP: already cleared this session → through.
        if ($request->session()->get(self::SESSION_KEY) === true) {
            return $next($request);
        }

        // A pure JSON/XHR client cannot follow the redirect (mirrors the confine
        // middleware); answer 403 instead of a 302 to an HTML challenge.
        if ($this->expectsHardFailure($request)) {
            abort(403);
        }

        // Remember where they were headed, then send them to the challenge.
        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route('admin.otp.show');
    }
}
