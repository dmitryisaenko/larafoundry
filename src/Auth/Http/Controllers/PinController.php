<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Http\Controllers;

use Dmitryisaenko\LaraFoundry\ActivityLog\Facades\Activity;
use Dmitryisaenko\LaraFoundry\Auth\Models\UserSession;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Session PIN-lock management and entry (phase 1.4).
 *
 * Extracted and hardened from the donor's PinController. The donor model is kept
 * 1:1 — set a PIN from the profile, manually lock (all sessions), unlock by
 * entering it (this device only) — with the security holes fixed: the PIN is
 * bcrypt-hashed (donor stored it hashed too) and PIN entry is rate-limited with
 * a per-session attempt counter + lockout window, which the donor lacked
 * (a 4-digit PIN was brute-forceable without limit).
 */
class PinController extends Controller
{
    /**
     * The PIN-entry screen — shown while the current session is locked.
     */
    public function enter(Request $request): Response|RedirectResponse
    {
        $session = $this->currentSession($request->user(), $request);

        if ($session === null || ! $session->pin_locked) {
            return redirect()->intended('/');
        }

        return Inertia::render('Auth/EnterPin', [
            'length' => $this->pinLength(),
        ]);
    }

    /**
     * Set (or replace) the user's PIN.
     *
     * Replacing an EXISTING PIN requires the current session to be unlocked —
     * otherwise a locked session could set a fresh PIN and unlock with it,
     * defeating the lock (the `pin.*` allow-list lets a locked session reach this
     * route). Setting a PIN for the first time is fine. A fresh PIN clears any
     * stale lock state across the user's sessions.
     */
    public function enable(Request $request): RedirectResponse
    {
        $request->validate(['pin' => ['required', 'string', 'digits:'.$this->pinLength()]]);

        $user = $request->user();
        $session = $this->currentSession($user, $request);

        if ($user->hasPin() && $session !== null && $session->pin_locked) {
            throw ValidationException::withMessages([
                'pin' => __('larafoundry::auth.pin.locked_change'),
            ]);
        }

        $user->forceFill(['pin_code' => Hash::make($request->input('pin'))])->save();
        $this->sessionsOf($user)->update([
            'pin_locked' => false,
            'pin_attempts' => 0,
            'pin_locked_until' => null,
        ]);

        return back()->with('status', __('larafoundry::auth.pin.enabled'));
    }

    /**
     * Verify the PIN and unlock THIS session (rate-limited).
     */
    public function check(Request $request): RedirectResponse
    {
        $request->validate(['pin' => ['required', 'string', 'digits:'.$this->pinLength()]]);

        $user = $request->user();
        $session = $this->currentSession($user, $request);

        if ($session === null) {
            return redirect()->route('pin.enter');
        }

        if ($session->pin_locked_until !== null && $session->pin_locked_until->isFuture()) {
            throw ValidationException::withMessages([
                'pin' => __('larafoundry::auth.pin.locked_out', [
                    'minutes' => max(1, (int) ceil(now()->diffInSeconds($session->pin_locked_until) / 60)),
                ]),
            ]);
        }

        if ($user->checkPinCode($request->input('pin'))) {
            $session->forceFill([
                'pin_locked' => false,
                'pin_attempts' => 0,
                'pin_locked_until' => null,
                'last_activity' => now(),
            ])->save();

            return redirect()->intended('/');
        }

        $this->registerFailure($session);

        Activity::log(
            description: 'pin.failed',
            logName: 'auth',
            isSuccessful: false,
            responseCode: 422,
            geoSync: false,
        );

        throw ValidationException::withMessages(['pin' => __('larafoundry::auth.pin.invalid')]);
    }

    /**
     * Remove the PIN entirely — requires the current PIN to confirm.
     */
    public function disable(Request $request): RedirectResponse
    {
        $request->validate(['pin' => ['required', 'string']]);

        $user = $request->user();

        if (! $user->checkPinCode($request->input('pin'))) {
            throw ValidationException::withMessages(['pin' => __('larafoundry::auth.pin.invalid')]);
        }

        $user->forceFill(['pin_code' => null])->save();
        $this->sessionsOf($user)->update([
            'pin_locked' => false,
            'pin_attempts' => 0,
            'pin_locked_until' => null,
        ]);

        return back()->with('status', __('larafoundry::auth.pin.disabled'));
    }

    /**
     * Manually lock EVERY session of the user ("lock me everywhere").
     */
    public function lock(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasPin()) {
            $this->sessionsOf($user)->update(['pin_locked' => true]);
        }

        return redirect()->route('pin.enter');
    }

    /**
     * Count this failure; trip the lockout window once attempts hit the max.
     */
    protected function registerFailure(UserSession $session): void
    {
        $attempts = $session->pin_attempts + 1;

        if ($attempts >= (int) config('larafoundry.pin.max_attempts', 5)) {
            $session->forceFill([
                'pin_attempts' => 0,
                'pin_locked_until' => now()->addMinutes((int) config('larafoundry.pin.lockout_minutes', 15)),
            ])->save();

            return;
        }

        $session->forceFill(['pin_attempts' => $attempts])->save();
    }

    protected function currentSession(?Authenticatable $user, Request $request): ?UserSession
    {
        if ($user === null || ! $request->hasSession()) {
            return null;
        }

        return $this->sessionsOf($user)
            ->where('session_id', $request->session()->getId())
            ->first();
    }

    protected function sessionsOf(Authenticatable $user): Builder
    {
        return UserSession::query()->where('user_id', $user->getAuthIdentifier());
    }

    protected function pinLength(): int
    {
        return (int) config('larafoundry.pin.length', 4);
    }
}
