<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Settings\Http\Controllers\SettingsController;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Controllers\CreateCompanyController;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Controllers\EmployeeController;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Controllers\InvitationController;
use Dmitryisaenko\LaraFoundry\Tenancy\Http\Controllers\SwitchCompanyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry tenancy routes (teams mode only)
|--------------------------------------------------------------------------
| Loaded from the service provider only when tenancy.mode !== 'personal'.
| Company creation, switching, employee management and invitations. RBAC role
| routes are NOT here — they arrive in phase 1.3.
|
| Naming: `tenancy.*`. Two employee routes (self-removal request/cancel) are
| listed in config `tenancy.routes_without_active_tenant` so EnsureActiveTenant
| lets a member without an active company still reach them.
*/

Route::middleware('web')->group(function () {
    // Invitation landing page is reachable WHILE LOGGED OUT: a brand-new invitee
    // (no account yet) follows the email link, sees the company, then registers
    // or logs in. Accept/reject require auth; the controller additionally enforces
    // that the account's email matches the invite AND is verified (proven mailbox
    // ownership) — a token plus a string-matching email is NOT enough to join.
    Route::prefix('invitations')->name('tenancy.invitations.')->group(function () {
        Route::get('{token}', [InvitationController::class, 'show'])->name('show');

        Route::middleware('auth')->group(function () {
            Route::post('{token}/accept', [InvitationController::class, 'accept'])->name('accept');
            Route::post('{token}/reject', [InvitationController::class, 'reject'])->name('reject');
        });
    });
});

Route::middleware(['web', 'auth'])->group(function () {
    // Everything below needs a verified email (business features) and resolves
    // the active company (auto-selecting owned > member when none is set).
    Route::middleware(['verified', 'larafoundry.tenant.set'])->group(function () {
        // Company-creation wizard (3 steps, no billing).
        Route::prefix('companies')->name('tenancy.companies.')->group(function () {
            Route::get('create', [CreateCompanyController::class, 'create'])->name('create');
            Route::post('create/step1', [CreateCompanyController::class, 'storeStep1'])->name('store.step1');
            Route::post('create/step2', [CreateCompanyController::class, 'storeStep2'])->name('store.step2');
            Route::post('create/step3', [CreateCompanyController::class, 'storeStep3'])->name('store.step3');
        });

        // Switch the active company.
        Route::put('companies/{uuid}/switch', SwitchCompanyController::class)
            ->name('tenancy.companies.switch');

        // Employee management — requires an active company (with two exemptions
        // configured for self-removal).
        Route::middleware('larafoundry.tenant.required')
            ->prefix('employees')
            ->name('tenancy.employees.')
            ->group(function () {
                Route::get('/', [EmployeeController::class, 'index'])->name('index');
                Route::post('/', [EmployeeController::class, 'store'])->name('store');
                Route::post('invite', [EmployeeController::class, 'invite'])->name('invite');
                Route::post('invitations/{invitation}/resend', [EmployeeController::class, 'resendInvitation'])->name('invitations.resend');
                Route::delete('invitations/{invitation}', [EmployeeController::class, 'deleteInvitation'])->name('invitations.delete');
                Route::delete('/', [EmployeeController::class, 'removeEmployee'])->name('remove');
                Route::post('request-removal', [EmployeeController::class, 'requestRemoval'])->name('request-removal');
                Route::post('cancel-removal', [EmployeeController::class, 'cancelRemoval'])->name('cancel-removal');
            });

        // Company settings (phase 5.1) — requires an active company; the company
        // is the resolved active tenant, never an id from the request. The
        // controller gates view/update on the RBAC company.settings.* permissions.
        Route::middleware('larafoundry.tenant.required')->group(function () {
            Route::get('settings/company', [SettingsController::class, 'company'])->name('settings.company');
            Route::put('settings/company', [SettingsController::class, 'updateCompany'])->name('settings.company.update');
        });
    });
});
