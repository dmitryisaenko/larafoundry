<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Onboarding\Http\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry onboarding routes (phase 5.4)
|--------------------------------------------------------------------------
| The getting-started checklist's dismiss endpoint — every authenticated user,
| any tenancy mode. The dismissed flag is user-scoped (resolved server-side from
| the caller), so there is no tenant middleware here: it needs no active company.
*/

Route::middleware(['web', 'auth'])
    ->prefix('onboarding')
    ->name('onboarding.')
    ->group(function () {
        Route::post('dismiss', [OnboardingController::class, 'dismiss'])->name('dismiss');
    });
