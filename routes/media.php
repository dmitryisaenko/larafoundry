<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Media\Http\Controllers\PrivateFileController;
use Illuminate\Support\Facades\Route;

/*
| LaraFoundry — media routes (phase 2.4)
|
| One route: the signed door to private-disk files. `signed` proves the URL was
| minted by the app and has not expired; `auth` requires a logged-in user. A
| host that needs per-record authorization (e.g. "only this order's owner may
| download its invoice") layers its own gate on top by minting the temporaryUrl
| only after its own check — this route guarantees the URL is unforgeable and
| short-lived, not who is allowed to mint it.
*/
Route::middleware(['web', 'auth', 'signed'])
    ->get('larafoundry/media/private', PrivateFileController::class)
    ->name('larafoundry.media.private');
