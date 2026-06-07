<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Legal\Http\Controllers\LegalPageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry public legal routes (phase 5.3)
|--------------------------------------------------------------------------
| The public Terms / Privacy / Cookie pages. Open to guests and authenticated
| users alike — visitors read the terms before they ever sign in. The controller
| 404s an unregistered slug or an unpublished page, so a placeholder default is
| never served as real legal text.
*/

Route::middleware('web')->group(function () {
    Route::get('legal/{slug}', [LegalPageController::class, 'show'])
        ->name('larafoundry.legal.show');
});
