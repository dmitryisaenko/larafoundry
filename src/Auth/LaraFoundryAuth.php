<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

/**
 * One-call wiring helpers a host invokes from its FortifyServiceProvider.
 *
 * Fortify is headless: it owns the routes but the app must tell it which view
 * each one renders. Rather than make every host hand-write seven
 * `Fortify::xxxView(...)` closures pointing at our Inertia pages, the core
 * exposes a single registrar (decision D1a). The pages are published from this
 * package, so they exist in the host's resources after `vendor:publish`.
 */
class LaraFoundryAuth
{
    /**
     * Point every Fortify auth view at the core's published Inertia pages.
     *
     * Call from FortifyServiceProvider::boot(). Pairs with `views => false` in
     * the host's fortify config (SPA mode) — these closures supply the GET
     * screens while Fortify still owns the POST endpoints.
     */
    public static function registerFortifyViews(): void
    {
        Fortify::loginView(fn () => Inertia::render('Auth/Login', [
            'canResetPassword' => true,
            'status' => session('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('Auth/Register'));

        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('Auth/ResetPassword', [
            'email' => $request->input('email'),
            'token' => $request->route('token'),
        ]));

        Fortify::verifyEmailView(fn () => Inertia::render('Auth/VerifyEmail', [
            'status' => session('status'),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('Auth/ConfirmPassword'));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('Auth/TwoFactorChallenge'));
    }
}
