<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Legal\Http\Controllers\Admin;

use Dmitryisaenko\LaraFoundry\Legal\Http\Requests\PreviewLegalPageRequest;
use Dmitryisaenko\LaraFoundry\Legal\Http\Requests\UpdateLegalPageRequest;
use Dmitryisaenko\LaraFoundry\Legal\Models\LegalPage;
use Dmitryisaenko\LaraFoundry\Legal\Support\LegalPageRepository;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The legal-page editor in the operator console (phase 5.3), super-admin only.
 *
 * Edit-only by design (mirrors the email-template editor, decision D-5.1-10): an
 * operator edits the title/body of the pages the platform ships (resolved from the
 * config registry), previews a render and publishes — but cannot create or delete
 * pages. New legal pages arrive by adding a registry entry; this editor picks them
 * up automatically. The whole zone is behind `larafoundry.admin` (+ OTP); each
 * action re-checks the super-admin policy as defence-in-depth.
 */
class LegalPageController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly LegalPageRepository $pages,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', LegalPage::class);

        return Inertia::render('Admin/Legal/Index', [
            'pages' => $this->pages->all(),
        ]);
    }

    public function edit(string $slug): Response
    {
        $this->authorize('view', LegalPage::class);

        $page = $this->pages->find($slug);

        abort_if($page === null, 404);

        return Inertia::render('Admin/Legal/Edit', [
            'page' => $page,
            'locales' => $this->pages->availableLocales(),
        ]);
    }

    public function update(string $slug, UpdateLegalPageRequest $request): RedirectResponse
    {
        abort_unless($this->pages->isRegistered($slug), 404);

        $this->pages->save($slug, [
            'title' => (array) $request->input('title', []),
            'body_html' => (array) $request->input('body_html', []),
            'is_published' => $request->boolean('is_published'),
            'bump_version' => $request->boolean('bump_version'),
        ]);

        return back()->with('status', __('larafoundry::legal.saved'));
    }

    /**
     * Sanitize unsaved editor input server-side — the editor shows it in a
     * sandboxed iframe (the same defence-in-depth pair the email editor uses).
     */
    public function preview(string $slug, PreviewLegalPageRequest $request): JsonResponse
    {
        abort_unless($this->pages->isRegistered($slug), 404);

        return response()->json([
            'html' => $this->pages->preview((string) $request->input('body_html', '')),
        ]);
    }
}
