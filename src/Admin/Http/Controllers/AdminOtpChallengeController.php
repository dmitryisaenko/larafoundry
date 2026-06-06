<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Admin\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Http\Middleware\EnsureAdminOtpVerified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Fortify\Fortify;

/**
 * The OTP step-up challenge for the operator console (phase 1.4).
 *
 * Reached when {@see EnsureAdminOtpVerified} finds a super-admin who has not yet
 * cleared the step-up this session. It re-verifies the operator's own Fortify
 * per-user 2FA (NOT a shared secret) — a TOTP code OR a recovery code — and, on
 * success, sets the session flag the gate looks for, then forwards to wherever
 * they were headed.
 *
 * It lives behind `larafoundry.admin` (so only the super-admin can see it) but
 * deliberately NOT behind `larafoundry.admin.otp`, so the gate's redirect to it
 * cannot loop. Verification reuses Fortify's primitives so there is no
 * home-grown TOTP code: the provider's `verify()` for codes, `recoveryCodes()` +
 * `replaceRecoveryCode()` (single-use) for recovery codes.
 */
class AdminOtpChallengeController extends Controller
{
    public function __construct(
        protected TwoFactorAuthenticationProvider $provider,
    ) {}

    /**
     * Show the challenge — or skip it when there is nothing to challenge.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $redirect = $this->guardState($request);

        if ($redirect !== null) {
            return $redirect;
        }

        return Inertia::render('Auth/AdminOtpChallenge');
    }

    /**
     * Verify a TOTP or recovery code and mark the step-up as cleared.
     */
    public function verify(Request $request): RedirectResponse
    {
        $redirect = $this->guardState($request);

        if ($redirect !== null) {
            return $redirect;
        }

        $request->validate([
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $user = $request->user();

        if ($this->passesRecoveryCode($user, $request->input('recovery_code'))
            || $this->passesTotpCode($user, $request->input('code'))) {
            $request->session()->put(EnsureAdminOtpVerified::SESSION_KEY, true);
            ValidTwoFactorAuthenticationCodeProvided::dispatch($user);

            return redirect()->intended(route($this->consoleRoute()));
        }

        TwoFactorAuthenticationFailed::dispatch($user);

        throw ValidationException::withMessages([
            'code' => __('larafoundry::auth.admin_otp.invalid_code'),
        ]);
    }

    /**
     * Common pre-checks for both actions: send a super-admin without confirmed
     * 2FA to enrolment (or 403), and skip the screen if they already cleared it.
     */
    protected function guardState(Request $request): ?RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            $setupRoute = config('larafoundry.security.super_admin.two_factor_setup_route');

            if (is_string($setupRoute) && $setupRoute !== '') {
                return redirect()->route($setupRoute)
                    ->with('error', __('larafoundry::auth.admin_otp.setup_required'));
            }

            abort(403, __('larafoundry::auth.admin_otp.setup_required'));
        }

        if ($request->session()->get(EnsureAdminOtpVerified::SESSION_KEY) === true) {
            return redirect()->route($this->consoleRoute());
        }

        return null;
    }

    protected function consoleRoute(): string
    {
        return (string) config('larafoundry.security.super_admin.console_route', 'admin.dashboard.index');
    }

    /**
     * Validate a TOTP code against the user's decrypted secret.
     */
    protected function passesTotpCode(mixed $user, mixed $code): bool
    {
        if (! is_string($code) || $code === '') {
            return false;
        }

        return $this->provider->verify(
            Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
            $code,
        );
    }

    /**
     * Validate (and consume) a single-use recovery code, timing-safely.
     */
    protected function passesRecoveryCode(mixed $user, mixed $code): bool
    {
        if (! is_string($code) || $code === '') {
            return false;
        }

        $match = collect($user->recoveryCodes())->first(
            fn (string $stored): bool => hash_equals($stored, $code),
        );

        if ($match === null) {
            return false;
        }

        // Recovery codes are single-use: consume it (the trait persists).
        $user->replaceRecoveryCode($match);

        return true;
    }
}
