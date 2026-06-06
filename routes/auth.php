<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Http\Controllers\OAuthController;
use Dmitryisaenko\LaraFoundry\Auth\Http\Controllers\SessionController;
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

    // Session management — authenticated only. The literal `others` route is
    // declared before the `{session}` model-bound one so it wins the match
    // (otherwise the binder would try to resolve a UserSession with id "others").
    Route::middleware('auth')->group(function () {
        Route::delete('auth/sessions/others', [SessionController::class, 'destroyOthers'])
            ->name('larafoundry.sessions.destroy-others');

        Route::delete('auth/sessions/{session}', [SessionController::class, 'destroy'])
            ->whereNumber('session')
            ->name('larafoundry.sessions.destroy');
    });
});
