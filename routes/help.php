<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Help\Http\Controllers\HelpController;
use Dmitryisaenko\LaraFoundry\Help\Http\Controllers\HelpPictureController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry help (v0.48.0) - the public side
|--------------------------------------------------------------------------
| Open to guests and signed-in users alike. The pictures are always served
| (a host drawing the help on its own screens still needs them); the page
| only while `larafoundry-help.reader.enabled` - off, the route is not even
| registered, so the path stays free for the host's own help page.
| The editor lives in routes/admin.php.
*/

// Outside `web` on purpose: the response is cached publicly for a year, and the
// session stack would put the first visitor's session cookie on it - for a new
// upload that visitor is the admin whose editor shows the thumbnail.
Route::get('help/pictures/{name}', HelpPictureController::class)
    ->name('larafoundry.help.picture');

Route::middleware('web')->group(function () {
    if (config('larafoundry-help.reader.enabled', true)) {
        Route::get(trim((string) config('larafoundry-help.reader.path', 'help'), '/'), HelpController::class)
            ->name('larafoundry.help.index');
    }
});
