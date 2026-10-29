<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Http\Controllers\AffiliateDashboardController;
use Illuminate\Support\Facades\Route;

/*
| The partner's own affiliate dashboard routes (Ф4.4). Loaded by the provider with the
| configured prefix + middleware (web + auth) only when the program and the dashboard are
| enabled. Names stay `affiliate.dashboard` / `affiliate.opt-in` regardless of the prefix.
*/

Route::get('/', [AffiliateDashboardController::class, 'show'])->name('affiliate.dashboard');
Route::post('opt-in', [AffiliateDashboardController::class, 'optIn'])->name('affiliate.opt-in');
