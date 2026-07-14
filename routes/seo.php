<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Seo\Http\Controllers\RobotsController;
use Dmitryisaenko\LaraFoundry\Seo\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LaraFoundry public SEO routes (phase 5.2)
|--------------------------------------------------------------------------
| The crawler-facing /sitemap.xml and /robots.txt. Open to everyone — no auth,
| no admin gate. Each controller 404s when its feature is switched off in
| `larafoundry-seo`, so a host can drop either without touching routes.
*/

Route::middleware('web')->group(function () {
    Route::get('sitemap.xml', [SitemapController::class, 'index'])
        ->name('larafoundry.sitemap');

    Route::get('robots.txt', [RobotsController::class, 'index'])
        ->name('larafoundry.robots');
});
