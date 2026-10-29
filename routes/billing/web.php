<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Http\Controllers\BlockedController;
use Dmitryisaenko\LaraFoundry\Billing\Http\Controllers\ServicePaymentController;
use Illuminate\Support\Facades\Route;

/*
| The add-on's owner billing routes (sub-phase B7a). The provider loads this file with
| the configured prefix + middleware only when billing is enabled. Names stay `billing.*`
| regardless of the URL prefix so redirects/exemptions reference them stably. These are
| the exempt escape-hatch routes the access middleware never blocks.
*/

Route::get('pricing', [ServicePaymentController::class, 'pricing'])->name('billing.pricing');
Route::get('checkout', [ServicePaymentController::class, 'checkout'])->name('billing.checkout');
Route::post('payment', [ServicePaymentController::class, 'process'])->name('billing.payment.process');
Route::get('history', [ServicePaymentController::class, 'history'])->name('billing.history');
Route::get('blocked', BlockedController::class)->name('billing.blocked');
