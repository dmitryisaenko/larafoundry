<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Qr\Http\Controllers\QrLoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry API routes
|--------------------------------------------------------------------------
| The core's API surface, guarded by Sanctum. These endpoints are
| guard-agnostic by design: with Sanctum's stateful-domain config a
| same-domain web request authenticates via its session cookie, while a
| future native mobile app authenticates with a Bearer token — the same
| controller serves both. Loaded via loadRoutesFrom() under the `api`
| middleware group (which L13 registers unconditionally). The `/api` URL
| prefix + stateful middleware are the host's job: it calls withRouting(api:)
| / statefulApi() in bootstrap/app.php (wired in the host integration step).
|
| QR-login verify (phase 1.4 part 2) lands here.
*/

Route::middleware('api')->group(function () {
    // QR cross-device login — the authenticated device approves a scanned code.
    // Guard-agnostic: `auth:sanctum` accepts the web session cookie (current)
    // and a Bearer token (future native app). Tight throttle: this is the
    // brute-force surface for the token (donor hole #2). Gated by the same
    // master switch as the web-side QR routes, so disabling QR leaves no live
    // verify endpoint.
    if (config('larafoundry.auth.qr.enabled', true)) {
        Route::get('larafoundry/qr/verify/{id}/{token}', [QrLoginController::class, 'verify'])
            ->middleware(['auth:sanctum', 'throttle:10,1'])
            ->whereNumber('id')
            ->name('larafoundry.qr.verify');
    }
});
