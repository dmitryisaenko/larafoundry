<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Setup\Http\Controllers\SetupWizardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry setup wizard (v0.49.0)
|--------------------------------------------------------------------------
| Every signed-in user, any tenancy mode: the wizard's marks are the user's own,
| so there is no tenant middleware. Off (`larafoundry.setup_wizard.enabled`) -
| not registered at all, so the paths stay free for a host's own wizard.
*/

if (config('larafoundry.setup_wizard.enabled', true)) {
    Route::middleware(['web', 'auth', 'throttle:30,1'])
        ->prefix('setup-wizard')
        ->name('larafoundry.setup-wizard.')
        ->controller(SetupWizardController::class)
        ->group(function () {
            Route::get('state', 'state')->name('state');
            Route::post('later', 'later')->name('later');
            Route::post('done', 'done')->name('done');
            Route::post('again', 'again')->name('again');
        });
}
