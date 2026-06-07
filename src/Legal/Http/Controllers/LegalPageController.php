<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Legal\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Legal\Support\LegalPageRepository;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public legal page — Terms, Privacy, Cookie policy (phase 5.3).
 *
 * Open to everyone, guests included (visitors read the terms before signing up).
 * Renders the page for the active locale ONLY when it has been published; an
 * unregistered slug or an unpublished page 404s, so a shipped placeholder default
 * is never served as if it were real legal text. The body is purified by the
 * repository before it reaches the page, so the Vue page can render it as HTML.
 */
class LegalPageController extends Controller
{
    public function __construct(
        private readonly LegalPageRepository $pages,
    ) {}

    public function show(string $slug): Response
    {
        $page = $this->pages->publicPage($slug, app()->getLocale());

        abort_if($page === null, 404);

        return Inertia::render('Legal/Show', [
            'page' => $page,
        ]);
    }
}
