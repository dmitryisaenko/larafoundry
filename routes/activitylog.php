<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\ActivityLog\Http\Controllers\Admin\ActivityLogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry activity-log routes (super-admin only)
|--------------------------------------------------------------------------
| The first slice of the platform operator console (phase 2.1). Everything is
| behind `larafoundry.admin` (super-admin via VisitorStatus) on top of an
| authenticated, verified session. The log is global — no tenant scoping.
|
| Naming: `admin.activity-log.*`.
*/

Route::middleware(['web', 'auth', 'verified', 'larafoundry.admin', 'larafoundry.admin.otp'])
    ->prefix('admin/activity-log')
    ->name('admin.activity-log.')
    ->group(function () {
        Route::get('/', [ActivityLogController::class, 'index'])->name('index');
        Route::get('users/{user}', [ActivityLogController::class, 'forUser'])->name('user');
    });
