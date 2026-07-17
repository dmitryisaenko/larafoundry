<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Admin\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Auth\Actions\UpdateUserPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

/**
 * The operator's own self-service security ACTIONS (in-console).
 *
 * Lets the super-admin enrol Fortify per-user 2FA (TOTP), manage recovery codes
 * and change their password — all from inside `/admin`, without touching the
 * tenant profile screens (which the confine keeps them out of). The PAGE that
 * renders this surface is the operator profile hub's Security tab
 * ({@see ProfileController::show});
 * this controller holds only the mutating endpoints it posts to.
 *
 * WHY the ENROLMENT actions live OUTSIDE `larafoundry.admin.otp` (see
 * routes/admin.php): the OTP step-up gate redirects an operator WITHOUT confirmed
 * 2FA to `two_factor_setup_route` — which resolves to the hub's Security tab. If
 * enrolment were behind the gate, that redirect would loop and the operator could
 * never enrol. So `enable` / `confirm` (and password change, itself defended by
 * `current_password`) sit behind only `larafoundry.admin`. The DESTRUCTIVE actions
 * — `disableTwoFactor` / `regenerateRecoveryCodes` — instead live INSIDE the
 * step-up gate: they only matter post-enrolment, when the operator can pass the
 * step-up, so requiring a stepped-up session closes the stolen-cookie path.
 *
 * The QR and recovery codes are NOT exposed as re-fetchable GET endpoints. They
 * are surfaced through the hub's `show` props ONLY while enrolment is in progress
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
     * Step 1 — generate the secret + recovery codes so the operator can enrol. The
     * hub's Security tab then shows the QR + codes from the refreshed hub props.
     */
    public function enableTwoFactor(Request $request, EnableTwoFactorAuthentication $enable): RedirectResponse
    {
        $enable($request->user());

        return redirect()->route('admin.profile.show', ['tab' => 'security']);
    }

    /**
     * Step 2 — confirm enrolment with a TOTP code. Reuses Fortify's action, so a
     * bad code throws into the `confirmTwoFactorAuthentication` bag (the frontend
     * reads it via a matching errorBag).
     */
    public function confirmTwoFactor(Request $request, ConfirmTwoFactorAuthentication $confirm): RedirectResponse
    {
        $confirm($request->user(), (string) $request->input('code'));

        return redirect()->route('admin.profile.show', ['tab' => 'security'])
            ->with('message-info', __('larafoundry::auth.operator_security.two_factor_enabled'));
    }

    /**
     * Issue a fresh set of recovery codes (invalidates the old set). Behind the
     * step-up gate.
     */
    public function regenerateRecoveryCodes(Request $request, GenerateNewRecoveryCodes $generate): RedirectResponse
    {
        $generate($request->user());

        return redirect()->route('admin.profile.show', ['tab' => 'security'])
            ->with('message-info', __('larafoundry::auth.operator_security.recovery_codes_regenerated'));
    }

    /**
     * Disable 2FA entirely for the operator. Behind the step-up gate.
     */
    public function disableTwoFactor(Request $request, DisableTwoFactorAuthentication $disable): RedirectResponse
    {
        $disable($request->user());

        return redirect()->route('admin.profile.show', ['tab' => 'security'])
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

        return redirect()->route('admin.profile.show', ['tab' => 'security'])
            ->with('message-info', __('larafoundry::auth.operator_security.password_updated'));
    }
}
