<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Controllers\AffiliateController;
use Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Controllers\CommissionPayoutController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry Billing affiliate console routes (super-admin only, Ф4.4)
|--------------------------------------------------------------------------
| Loaded by the add-on provider only when billing + the admin console + the
| affiliate program are all on. Behind the same `larafoundry.admin` gate as
| the core operator console, so these `admin.affiliates.*` / `admin.payouts.*`
| routes sit alongside `admin.payments.*` / `admin.promo-codes.*`. The
| `{affiliate}` route key is the default model binding.
*/

Route::middleware((array) config('larafoundry-billing.admin.middleware', ['web', 'auth', 'verified', 'larafoundry.admin', 'larafoundry.admin.otp']))
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::prefix('affiliates')->name('affiliates.')->group(function () {
            Route::get('/', [AffiliateController::class, 'index'])->name('index');

            // Literal segment before any model-bound route so it is never shadowed;
            // throttled (autocomplete is hit per keystroke).
            Route::get('search-users', [AffiliateController::class, 'searchUsers'])
                ->middleware('throttle:'.config('larafoundry-billing.admin.user_search.throttle', '30,1'))
                ->name('search-users');

            Route::post('/', [AffiliateController::class, 'store'])->name('store');
            Route::put('{affiliate}', [AffiliateController::class, 'update'])->name('update');
        });

        Route::prefix('payouts')->name('payouts.')->group(function () {
            Route::get('/', [CommissionPayoutController::class, 'index'])->name('index');
            Route::get('export', [CommissionPayoutController::class, 'export'])->name('export');
            Route::post('approve', [CommissionPayoutController::class, 'approve'])->name('approve');
            Route::post('mark-paid', [CommissionPayoutController::class, 'markPaid'])->name('mark-paid');
            Route::post('void', [CommissionPayoutController::class, 'void'])->name('void');
        });
    });
