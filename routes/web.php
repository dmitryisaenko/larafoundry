<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Http\Controllers\LanguageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry web routes
|--------------------------------------------------------------------------
| Registered automatically via LaraFoundryServiceProvider::loadRoutesFrom().
| Module routes (auth, admin, …) will be added here.
*/

Route::middleware('web')->group(function () {
    // Language switch. POST (it changes state) so it carries CSRF protection,
    // and open to guests and authenticated users alike — visitors pick a
    // language before they ever sign in.
    Route::post('larafoundry/language', [LanguageController::class, 'switch'])
        ->name('larafoundry.language.switch');
});
