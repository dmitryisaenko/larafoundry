<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Notifications\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry notification-centre routes (phase 4.1)
|--------------------------------------------------------------------------
| The recipient's own inbox + bell. Behind `auth` and the account-active gate
| (a blocked user has no inbox), but NOT the admin gate — every authenticated
| user has notifications. The controller scopes every action to the caller's
| own notifications, so these need no per-row authorization.
*/

Route::middleware(['web', 'auth', 'larafoundry.account.active'])
    ->prefix('notifications')
    ->name('notifications.')
    ->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('recent', [NotificationController::class, 'recent'])->name('recent');
        Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::post('read-all', [NotificationController::class, 'readAll'])->name('read-all');
        Route::post('{notification}/read', [NotificationController::class, 'read'])->name('read');
    });
