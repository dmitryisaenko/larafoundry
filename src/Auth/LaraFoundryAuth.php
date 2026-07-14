<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth;

use Dmitryisaenko\LaraFoundry\Seo\Support\SeoManager;
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

        // The sensitive screens (reset password, confirm password, verify email,
        // 2FA challenge) are marked noindex,nofollow (phase 5.2): they carry
        // one-time tokens / step-up state and must never surface in a search
        // index. Login/register/forgot stay default-indexable. The noindex is set
        // on the shared SeoManager before the page renders, so both the
        // `@larafoundrySeo` head and the `seo` shared prop reflect it.
        Fortify::resetPasswordView(function (Request $request) {
            app(SeoManager::class)->noindex();

            return Inertia::render('Auth/ResetPassword', [
                'email' => $request->input('email'),
                'token' => $request->route('token'),
            ]);
        });

        Fortify::verifyEmailView(function () {
            app(SeoManager::class)->noindex();

            return Inertia::render('Auth/VerifyEmail', [
                'status' => session('status'),
            ]);
        });

        Fortify::confirmPasswordView(function () {
            app(SeoManager::class)->noindex();

            return Inertia::render('Auth/ConfirmPassword');
        });

        Fortify::twoFactorChallengeView(function () {
            app(SeoManager::class)->noindex();

            return Inertia::render('Auth/TwoFactorChallenge');
        });
    }
}
