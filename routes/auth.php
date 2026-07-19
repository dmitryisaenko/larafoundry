<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Http\Controllers\OAuthController;
use Dmitryisaenko\LaraFoundry\Auth\Http\Controllers\SessionController;
use Dmitryisaenko\LaraFoundry\Auth\MagicLink\Http\Controllers\MagicLinkController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry auth routes
|--------------------------------------------------------------------------
| The bulk of authentication (login/register/reset/verify/2FA) is served by
| Fortify's own routes. These are the pieces Fortify does not cover: OAuth
| sign-in and self-service session management. Loaded via loadRoutesFrom().
*/

Route::middleware('web')->group(function () {
    // OAuth — guest-facing redirect/callback. Provider list and the master
    // switch are enforced inside the controller against config.
    Route::middleware('guest')->group(function () {
        Route::get('auth/oauth/{provider}', [OAuthController::class, 'redirect'])
            ->name('larafoundry.oauth.redirect');
        Route::get('auth/oauth/{provider}/callback', [OAuthController::class, 'callback'])
            ->name('larafoundry.oauth.callback');
    });

    // Passwordless email sign-in (magic-link). Guest-facing, and — like the QR
    // master switch — registered ONLY when the feature is enabled, so a disabled
    // feature leaves no live surface (the routes 404) rather than merely hiding
    // the UI. Route NAMES are a host contract (Comentor's Login.vue posts to
    // `magic-link.request`), so they must not change. `request` is throttled per
    // (email + IP) by the named limiter; `verify` is `signed` (tamper + expiry).
    if (config('larafoundry.auth.magic_link.enabled', false)) {
        Route::middleware('guest')->group(function () {
            Route::post('auth/magic/request', [MagicLinkController::class, 'request'])
                ->middleware('throttle:larafoundry-magic-link')
                ->name('magic-link.request');

            Route::get('auth/magic/verify', [MagicLinkController::class, 'verify'])
                ->middleware('signed')
                ->name('magic-link.verify');
        });
    }

    // Session management — authenticated only. The literal `others` route is
    // declared before the `{session}` model-bound one so it wins the match
    // (otherwise the binder would try to resolve a UserSession with id "others").
    Route::middleware('auth')->group(function () {
        Route::delete('auth/sessions/others', [SessionController::class, 'destroyOthers'])
            ->name('larafoundry.sessions.destroy-others');

        Route::delete('auth/sessions/{session}', [SessionController::class, 'destroy'])
            ->whereNumber('session')
            ->name('larafoundry.sessions.destroy');

        // Revoke one API-token device (mobile app / other API client). Separate
        // path from sessions/* because it deletes a personal_access_token, not a
        // tracked web session.
        Route::delete('auth/tokens/{token}', [SessionController::class, 'destroyToken'])
            ->whereNumber('token')
            ->name('larafoundry.sessions.destroy-token');
    });
});
