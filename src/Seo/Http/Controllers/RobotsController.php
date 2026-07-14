<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Seo\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;

/**
 * The public /robots.txt (phase 5.2).
 *
 * A deliberately simple body: allow all crawlers, and append the absolute
 * Sitemap URL when the sitemap is enabled (so a crawler discovers it without
 * guessing). 404s when robots.txt is disabled in config. A host that needs
 * per-path Disallow rules publishes its own robots.txt or overrides this route.
 */
class RobotsController extends Controller
{
    public function index(): Response
    {
        abort_unless((bool) config('larafoundry-seo.robots.enabled', true), 404);

        $lines = [
            'User-agent: *',
            'Allow: /',
        ];

        if ((bool) config('larafoundry-seo.sitemap.enabled', true) && Route::has('larafoundry.sitemap')) {
            $lines[] = 'Sitemap: '.route('larafoundry.sitemap');
        }

        return response(implode("\n", $lines)."\n", 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
