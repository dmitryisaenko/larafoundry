<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Settings\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry settings routes (phase 5.1)
|--------------------------------------------------------------------------
| Account (user-scope) settings — every authenticated user, any tenancy mode.
| Company-scope settings live in routes/tenancy.php (teams only, behind the
| active-tenant middleware so the company is resolved server-side). App-scope
| settings live in routes/admin.php, behind the super-admin gate.
*/

Route::middleware(['web', 'auth'])
    ->prefix('settings')
    ->name('settings.')
    ->group(function () {
        Route::get('/', [SettingsController::class, 'account'])->name('account');
        Route::put('account', [SettingsController::class, 'updateAccount'])->name('account.update');
    });
