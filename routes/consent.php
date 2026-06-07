<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Legal\Http\Controllers\ConsentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry consent routes (phase 5.3 part B)
|--------------------------------------------------------------------------
| Cookie consent is open to guests and authenticated users alike (a visitor
| records a choice before signing in). The Terms re-acceptance screen + submit
| sit behind `auth`; the re-accept gate (`larafoundry.terms`) redirects a user
| with a stale Terms version here and excludes these route names, so there is no
| redirect loop. The `terms/accept` path is deliberately distinct from the public
| `legal/{slug}` pages.
*/

Route::middleware('web')->group(function () {
    Route::post('consent/cookies', [ConsentController::class, 'storeCookie'])
        ->name('larafoundry.consent.cookies');
});

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('terms/accept', [ConsentController::class, 'showTerms'])
        ->name('larafoundry.consent.terms.show');

    Route::post('terms/accept', [ConsentController::class, 'acceptTerms'])
        ->name('larafoundry.consent.terms.accept');
});
