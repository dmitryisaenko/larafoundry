<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Profile\Http\Controllers\AccountController;
use Dmitryisaenko\LaraFoundry\Profile\Http\Controllers\AvatarController;
use Dmitryisaenko\LaraFoundry\Profile\Http\Controllers\DataExportController;
use Dmitryisaenko\LaraFoundry\Profile\Http\Controllers\ProfileController;
use Dmitryisaenko\LaraFoundry\Profile\Http\Controllers\UiSettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry profile routes (phase 5.1)
|--------------------------------------------------------------------------
| The self-service profile hub and the tabs this phase adds (avatar, UI
| preferences, account deletion). Name/email/password are served by Fortify's
| own endpoints; per-session revoke lives in routes/auth.php with the rest of
| session management. The `/profile` prefix is in the email-verification
| except-prefixes (config), so a user can reach it to re-verify after changing
| their email — do not add the `verified` middleware here.
*/

Route::middleware(['web', 'auth'])
    ->prefix('profile')
    ->name('profile.')
    ->group(function () {
        Route::get('/', [ProfileController::class, 'index'])->name('index');

        Route::post('avatar', [AvatarController::class, 'update'])->name('avatar.update');
        Route::delete('avatar', [AvatarController::class, 'destroy'])->name('avatar.destroy');

        Route::put('ui-settings', [UiSettingsController::class, 'update'])->name('ui-settings.update');

        // GDPR data portability (phase 5.3): a synchronous JSON download of the
        // user's own data. Rate-limited (default 3/day) against repeated dumps.
        Route::get('data/export', [DataExportController::class, 'download'])
            ->name('data.export')
            ->middleware('throttle:'.config('larafoundry.profile.data_export.throttle', '3,1440'));

        Route::delete('account', [AccountController::class, 'destroy'])->name('account.destroy');
    });
