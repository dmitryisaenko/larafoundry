<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Qr\Http\Controllers\QrLoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry QR cross-device login routes (phase 1.4 part 2)
|--------------------------------------------------------------------------
| The web (guest) side of QR login: generate a code, then poll for approval.
| Both are throttled (the donor had no rate-limit anywhere — donor hole #2):
| generate is cheap but mintable in a loop; poll is hit every couple of
| seconds, so its limit is higher. The verify endpoint (the authenticated
| device's approval) lives in routes/api.php behind auth:sanctum.
|
| Naming: `larafoundry.qr.*`.
*/

// The master switch: when QR login is disabled the routes are not registered at
// all, so a disabled feature leaves no live auth surface (matching how the PIN
// middleware no-ops when its switch is off).
if (config('larafoundry.auth.qr.enabled', true)) {
    Route::middleware(['web', 'guest'])
        ->prefix('larafoundry/qr')
        ->name('larafoundry.qr.')
        ->group(function () {
            Route::post('generate', [QrLoginController::class, 'generate'])
                ->middleware('throttle:10,1')
                ->name('generate');

            Route::post('poll', [QrLoginController::class, 'poll'])
                ->middleware('throttle:40,1')
                ->name('poll');
        });
}
