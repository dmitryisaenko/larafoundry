<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tickets\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry helpdesk — user routes (phase 4.2)
|--------------------------------------------------------------------------
| The customer's own tickets. Behind `auth` only — NOT the account-active gate:
| a blocked user must still be able to reach support (it is their only channel
| to the operator when their company is blocked), and an unverified one too. The
| controller scopes every action to the caller via the ownership policy, and the
| route key is the uuid so ids are never exposed.
|
| `store` and `storeMessage` are throttled (security finding S2) so a script
| cannot flood the support queue; the limits come from config.
*/

Route::middleware(['web', 'auth'])
    ->prefix('tickets')
    ->name('tickets.')
    ->group(function () {
        Route::get('/', [TicketController::class, 'index'])->name('index');
        Route::get('create', [TicketController::class, 'create'])->name('create');

        Route::post('/', [TicketController::class, 'store'])
            ->middleware('throttle:'.config('larafoundry-tickets.rate_limit.create', '5,1'))
            ->name('store');

        Route::get('{ticket:uuid}', [TicketController::class, 'show'])->name('show');

        Route::post('{ticket:uuid}/messages', [TicketController::class, 'storeMessage'])
            ->middleware('throttle:'.config('larafoundry-tickets.rate_limit.reply', '20,1'))
            ->name('messages.store');
    });
