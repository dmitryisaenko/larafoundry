<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares the user's appearance preference (light / dark / system) with views.
 *
 * Read from the `appearance` cookie set client-side; defaults to 'system'.
 * The Blade root template uses it to set the initial theme class before
 * hydration, avoiding a flash of the wrong theme.
 */
class HandleAppearance
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appearance = $request->cookie('appearance');

        if (! in_array($appearance, ['light', 'dark', 'system'], true)) {
            $appearance = 'system';
        }

        View::share('appearance', $appearance);

        return $next($request);
    }
}
