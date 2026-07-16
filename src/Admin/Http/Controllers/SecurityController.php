<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Admin\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Auth\Actions\UpdateUserPassword;
use Dmitryisaenko\LaraFoundry\Http\Middleware\EnsureAdminOtpVerified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

/**
 * The operator's own self-service security page (in-console).
 *
 * Lets the super-admin enrol Fortify per-user 2FA (TOTP), manage recovery codes,
 * set a session PIN and change their password — all from inside `/admin`, without
 * touching the tenant profile screens (which the confine keeps them out of, and
 * whose identity is deliberately separate from the operator's).
 *
 * WHY the ENROLMENT actions live OUTSIDE `larafoundry.admin.otp` (see
 * routes/admin.php): the OTP step-up gate redirects an operator WITHOUT confirmed
 * 2FA to `two_factor_setup_route` — which defaults to this page. If the page were
 * behind the gate, that redirect would loop and the operator could never enrol. So
 * `show` / `enable` / `confirm` (and password change, itself defended by
 * `current_password`) sit behind only `larafoundry.admin`. The DESTRUCTIVE actions
 * — `disableTwoFactor` / `regenerateRecoveryCodes` — instead live INSIDE the
 * step-up gate: they only matter post-enrolment, when the operator can pass the
 * step-up, so requiring a stepped-up session closes the stolen-cookie path.
 *
 * The QR and recovery codes are NOT exposed as re-fetchable GET endpoints. They
 * are surfaced through {@see show} props ONLY while enrolment is in progress
 * (secret set, not yet confirmed) or, for review, to a session that has cleared
 * the OTP step-up. A non-stepped-up session therefore cannot read the live TOTP
 * secret or recovery codes — which the step-up challenge itself accepts, so
 * leaking them would defeat the gate.
 *
 * The 2FA/password mutations proxy Fortify's / the core's own action classes
 * rather than Fortify's `/user/*` routes: those are unnamed (the confine allow-list
 * matches by route NAME) and profile-scoped. Proxying keeps everything under
 * `admin.*` (already allowed) with no logic duplication. Validation errors keep the
 * action's own error bags (`confirmTwoFactorAuthentication`, `updatePassword`) — the
 * frontend forms pass a matching `errorBag` so they surface cleanly.
 */
class SecurityController extends Controller
{
    /**
     * Render the operator security page with the current 2FA / PIN / password state.
     *
     * `two_factor_setup` carries the QR + recovery codes ONLY during enrolment
     * (secret set, not confirmed). `recovery_codes` carries them for review ONLY
     * once confirmed AND the session has cleared the OTP step-up. Both are null
     * otherwise, so a non-stepped-up session never receives the operator's secrets.
     */
    public function show(Request $request): Response
    {
        $user = $request->user();

        // Detect state from the columns directly (secret + confirmation), NOT from
        // Fortify's hasEnabledTwoFactorAuthentication() — that predicate collapses
        // the "secret set but not yet confirmed" state into "enabled" when the
        // global Fortify `confirm` feature is off, which would hide the enrolment
        // QR. The operator page always runs a confirm step, so it keys off
        // `two_factor_confirmed_at` and stays correct regardless of that flag.
        $hasSecret = $user->two_factor_secret !== null;
        $confirmed = $hasSecret && $user->two_factor_confirmed_at !== null;
        $enrolling = $hasSecret && ! $confirmed;
        $steppedUp = $request->session()->get(EnsureAdminOtpVerified::SESSION_KEY) === true;

        return Inertia::render('Admin/Security/Index', [
            'two_factor_enabled' => $confirmed,
            // Enrolment-only: the QR to scan and the recovery codes to store, shown
            // once while the operator is setting 2FA up (before they can step up).
            'two_factor_setup' => $enrolling
                ? [
                    'svg' => $user->twoFactorQrCodeSvg(),
                    'recovery_codes' => $user->recoveryCodes(),
                ]
                : null,
            // Review-only: the current recovery codes, exposed strictly to a
            // stepped-up session (never to a bare/stolen cookie).
            'recovery_codes' => ($confirmed && $steppedUp) ? $user->recoveryCodes() : null,
            // Whether destructive 2FA actions (disable / regenerate) are reachable
            // this session — they sit behind the step-up gate, so the UI hides them
            // for a non-stepped-up session rather than bouncing the click.
            'can_manage_two_factor' => $steppedUp,
            'has_pin' => $user->hasPin(),
            'pin_length' => (int) config('larafoundry.pin.length', 4),
            'has_password' => $user->password !== null,
        ]);
    }

    /**
     * Step 1 — generate the secret + recovery codes so the operator can enrol. The
     * page then shows the QR + codes from the refreshed `show()` props.
     */
    public function enableTwoFactor(Request $request, EnableTwoFactorAuthentication $enable): RedirectResponse
    {
        $enable($request->user());

        return redirect()->route('admin.security.show');
    }

    /**
     * Step 2 — confirm enrolment with a TOTP code. Reuses Fortify's action, so a
     * bad code throws into the `confirmTwoFactorAuthentication` bag (the frontend
     * reads it via a matching errorBag).
     */
    public function confirmTwoFactor(Request $request, ConfirmTwoFactorAuthentication $confirm): RedirectResponse
    {
        $confirm($request->user(), (string) $request->input('code'));

        return redirect()->route('admin.security.show')
            ->with('message-info', __('larafoundry::auth.operator_security.two_factor_enabled'));
    }

    /**
     * Issue a fresh set of recovery codes (invalidates the old set). Behind the
     * step-up gate.
     */
    public function regenerateRecoveryCodes(Request $request, GenerateNewRecoveryCodes $generate): RedirectResponse
    {
        $generate($request->user());

        return redirect()->route('admin.security.show')
            ->with('message-info', __('larafoundry::auth.operator_security.recovery_codes_regenerated'));
    }

    /**
     * Disable 2FA entirely for the operator. Behind the step-up gate.
     */
    public function disableTwoFactor(Request $request, DisableTwoFactorAuthentication $disable): RedirectResponse
    {
        $disable($request->user());

        return redirect()->route('admin.security.show')
            ->with('message-info', __('larafoundry::auth.operator_security.two_factor_disabled'));
    }

    /**
     * Change the operator's password. Reuses the core UpdateUserPassword action
     * (current-password required, Password::defaults() strength, PasswordUpdated
     * event). Errors land in the `updatePassword` bag (the frontend reads it via a
     * matching errorBag).
     */
    public function updatePassword(Request $request, UpdateUserPassword $updater): RedirectResponse
    {
        $updater->update($request->user(), $request->only([
            'current_password',
            'password',
            'password_confirmation',
        ]));

        return redirect()->route('admin.security.show')
            ->with('message-info', __('larafoundry::auth.operator_security.password_updated'));
    }
}
