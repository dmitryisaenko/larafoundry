<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\AdminOtpChallengeController;
use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\CompanyController;
use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\DashboardController;
use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\ImpersonateController;
use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry operator-console routes (super-admin only)
|--------------------------------------------------------------------------
| Phase 2.3: user management + impersonation, on top of the activity log
| (phase 2.1, `admin.activity-log.*`). Phase 3.3 adds company management
| (`admin.companies.*`). Everything is behind `larafoundry.admin` (super-admin
| via VisitorStatus) on an authenticated, verified session.
|
| `impersonate.leave` is the one exception: it lives OUTSIDE the admin gate,
| because while impersonating the actor IS the target user (not an admin), so
| the gate would lock them out of their own exit. It only needs auth.
*/

Route::middleware(['web', 'auth', 'verified', 'larafoundry.admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        // OTP step-up challenge (phase 1.4). Deliberately OUTSIDE the
        // `larafoundry.admin.otp` gate below — the gate redirects here, so
        // gating this route too would loop. The POST is rate-limited like
        // Fortify's own two-factor challenge.
        Route::get('otp', [AdminOtpChallengeController::class, 'show'])->name('otp.show');
        Route::post('otp', [AdminOtpChallengeController::class, 'verify'])
            ->middleware('throttle:6,1')
            ->name('otp.verify');

        // The console proper: everything past the OTP step-up gate.
        Route::middleware('larafoundry.admin.otp')->group(function () {
            // The console landing screen (phase 3.4): URL /admin.
            Route::get('/', [DashboardController::class, 'index'])->name('dashboard.index');

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

            Route::prefix('companies')->name('companies.')->group(function () {
                Route::get('/', [CompanyController::class, 'index'])->name('index');
                Route::get('search', [CompanyController::class, 'search'])->name('search');
                Route::get('{company}', [CompanyController::class, 'show'])->name('show');
                Route::post('{company}/block', [CompanyController::class, 'block'])->name('block');
                Route::post('{company}/unblock', [CompanyController::class, 'unblock'])->name('unblock');
            });

            Route::post('impersonate/{user}', [ImpersonateController::class, 'take'])
                ->name('impersonate.take');
        });
    });

// Leaving impersonation runs as the impersonated user — outside the admin gate.
Route::middleware(['web', 'auth'])
    ->post('impersonate/leave', [ImpersonateController::class, 'leave'])
    ->name('impersonate.leave');
