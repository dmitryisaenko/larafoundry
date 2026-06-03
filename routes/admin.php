<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\ImpersonateController;
use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry operator-console routes (super-admin only)
|--------------------------------------------------------------------------
| Phase 2.3: user management + impersonation, on top of the activity log
| (phase 2.1, `admin.activity-log.*`). Everything is behind `larafoundry.admin`
| (super-admin via VisitorStatus) on an authenticated, verified session.
|
| `impersonate.leave` is the one exception: it lives OUTSIDE the admin gate,
| because while impersonating the actor IS the target user (not an admin), so
| the gate would lock them out of their own exit. It only needs auth.
*/

Route::middleware(['web', 'auth', 'verified', 'larafoundry.admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::prefix('users')->name('users.')->group(function () {
            Route::get('/', [UserController::class, 'index'])->name('index');
            Route::get('search', [UserController::class, 'search'])->name('search');
            Route::get('create', [UserController::class, 'create'])->name('create');
            Route::post('/', [UserController::class, 'store'])->name('store');
            Route::get('{user}/edit', [UserController::class, 'edit'])->name('edit');
            Route::put('{user}', [UserController::class, 'update'])->name('update');
            Route::post('{user}/block', [UserController::class, 'block'])->name('block');
            Route::post('{user}/unblock', [UserController::class, 'unblock'])->name('unblock');
            Route::delete('{user}', [UserController::class, 'destroy'])->name('destroy');
            Route::post('{user}/restore', [UserController::class, 'undelete'])->name('restore');
        });

        Route::post('impersonate/{user}', [ImpersonateController::class, 'take'])
            ->name('impersonate.take');
    });

// Leaving impersonation runs as the impersonated user — outside the admin gate.
Route::middleware(['web', 'auth'])
    ->post('impersonate/leave', [ImpersonateController::class, 'leave'])
    ->name('impersonate.leave');
