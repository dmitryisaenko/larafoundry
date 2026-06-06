<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Http\Controllers\PinController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry session PIN-lock routes (phase 1.4)
|--------------------------------------------------------------------------
| Available to any authenticated user (PIN-lock is not role-specific). These
| route names match the `pin.*` allow-list in both CheckPinLock and the
| super-admin confine/allow lists, so a locked session can always reach the
| unlock screen. `check` carries a coarse per-IP throttle on top of the
| per-session attempt counter + lockout window enforced in the controller.
|
| Naming: `pin.*`.
*/

Route::middleware(['web', 'auth'])
    ->prefix('pin')
    ->name('pin.')
    ->group(function () {
        Route::get('/', [PinController::class, 'enter'])->name('enter');
        Route::post('check', [PinController::class, 'check'])
            ->middleware('throttle:10,1')
            ->name('check');
        Route::post('enable', [PinController::class, 'enable'])->name('enable');
        Route::post('disable', [PinController::class, 'disable'])->name('disable');
        Route::post('lock', [PinController::class, 'lock'])->name('lock');
    });
