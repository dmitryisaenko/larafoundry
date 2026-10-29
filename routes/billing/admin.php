<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Controllers\PaymentController;
use Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Controllers\PromoCodeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry Billing operator-console routes (super-admin only, B7b)
|--------------------------------------------------------------------------
| Loaded by the add-on provider only when billing is on AND admin.enabled.
| Behind the core's `larafoundry.admin` gate on an authenticated, verified
| session — the same group the core operator console uses — so these
| `admin.payments.*` / `admin.promo-codes.*` routes sit alongside the core's
| `admin.users.*` / `admin.companies.*`. The payments console is read-only;
| the promo console is full CRUD over the B5 backend.
|
| The `{promo_code}` route key is snake_case on purpose: the B5
| Store/UpdatePromoCodeRequest read `$this->route('promo_code')` for the
| unique-ignore and percentage-cap fallback, so the implicit-bound model must
| arrive under that key.
*/

Route::middleware((array) config('larafoundry-billing.admin.middleware', ['web', 'auth', 'verified', 'larafoundry.admin', 'larafoundry.admin.otp']))
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');

        Route::prefix('promo-codes')->name('promo-codes.')->group(function () {
            Route::get('/', [PromoCodeController::class, 'index'])->name('index');

            // Literal segment declared before the `{promo_code}` routes so it is never
            // shadowed by the model-bound ones; throttled (the donor had no limit).
            Route::get('search-users', [PromoCodeController::class, 'searchUsers'])
                ->middleware('throttle:'.config('larafoundry-billing.admin.user_search.throttle', '30,1'))
                ->name('search-users');

            Route::get('create', [PromoCodeController::class, 'create'])->name('create');
            Route::post('/', [PromoCodeController::class, 'store'])->name('store');
            Route::get('{promo_code}/edit', [PromoCodeController::class, 'edit'])->name('edit');
            Route::put('{promo_code}', [PromoCodeController::class, 'update'])->name('update');
            Route::patch('{promo_code}/toggle', [PromoCodeController::class, 'toggleActive'])->name('toggle');
        });
    });
