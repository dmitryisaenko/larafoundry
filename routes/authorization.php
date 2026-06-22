<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Authorization\Http\Controllers\EmployeeAccessController;
use Dmitryisaenko\LaraFoundry\Authorization\Http\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry authorization routes (teams mode only)
|--------------------------------------------------------------------------
| Loaded from the service provider only when tenancy.mode !== 'personal' — role
| management is a company concept. Everything needs a verified user with an active
| company resolved (tenant.set) and required (tenant.required): roles live inside a
| company.
|
| {role} and {user} are bound as raw ids and resolved THROUGH the active company in
| the controllers (anti-IDOR), so a global route-model binding can't leak another
| tenant's row. Naming: `authorization.*` (distinct from phase 1.2 `tenancy.*`).
*/

Route::middleware(['web', 'auth'])->group(function () {
    Route::middleware(['verified', 'larafoundry.tenant.set', 'larafoundry.tenant.required'])->group(function () {
        Route::prefix('roles')->name('authorization.roles.')->group(function () {
            Route::get('/', [RoleController::class, 'index'])->name('index');
            Route::get('create', [RoleController::class, 'create'])->name('create');
            Route::post('/', [RoleController::class, 'store'])->name('store');
            Route::get('{role}/edit', [RoleController::class, 'edit'])->name('edit');
            Route::put('{role}', [RoleController::class, 'update'])->name('update');
            Route::delete('{role}', [RoleController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('employees')->name('authorization.employees.')->group(function () {
            Route::put('{user}/roles', [EmployeeAccessController::class, 'syncRoles'])->name('roles');
            Route::put('{user}/permissions', [EmployeeAccessController::class, 'updatePermissions'])->name('permissions');
        });
    });
});
