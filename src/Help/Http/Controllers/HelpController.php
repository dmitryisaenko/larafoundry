<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Help\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Help\Support\HelpBook;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public help page (`/help`, guests included): one tab per section.
 *
 * `?section=` opens that tab - a link to help from inside a part of the app should
 * land on that part's help. An unknown or missing value opens the first tab rather
 * than failing: the address is typed by hand often enough. No published section at
 * all is a 404, never an empty page.
 */
class HelpController extends Controller
{
    public function __invoke(Request $request, HelpBook $book): Response
    {
        $pages = $book->pages(app()->getLocale());

        abort_if($pages === [], 404);

        $asked = $request->query('section');
        $open = is_string($asked) && array_key_exists($asked, $pages) ? $asked : array_key_first($pages);

        return Inertia::render('Help/Index', [
            'pages' => $pages,
            'open' => $open,
        ]);
    }
}
