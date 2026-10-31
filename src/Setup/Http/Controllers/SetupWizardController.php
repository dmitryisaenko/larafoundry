<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Setup\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Setup\Support\SetupWizard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The setup wizard's own endpoints (v0.49.0): what it opens with, and its three
 * ways out. Every step writes through the endpoint that already keeps its setting
 * (theme and date format - `profile.ui-settings.update`; the interface language -
 * `larafoundry.language.switch`; the name - Fortify's profile update; the photo -
 * `profile.avatar.update`). A second endpoint for any of them would be a second
 * copy of its rules.
 */
class SetupWizardController extends Controller
{
    public function __construct(private readonly SetupWizard $wizard) {}

    public function state(Request $request): JsonResponse
    {
        return response()->json($this->wizard->state($request->user()));
    }

    /** "Skip": shut until the next login; nothing is written on the account. */
    public function later(Request $request): JsonResponse
    {
        $this->refuseSomeoneElse($request);
        $request->session()->forget(SetupWizard::SESSION_KEY);
        $this->wizard->skip($request);

        return response()->json(['ok' => true]);
    }

    /**
     * "Done" or "Don't show again": never offered again unless asked for. Every part
     * asked on this request - a part asked elsewhere is still offered there.
     */
    public function done(Request $request): JsonResponse
    {
        $this->refuseSomeoneElse($request);
        $this->wizard->finish($request->user(), $request);
        $request->session()->forget(SetupWizard::SESSION_KEY);

        return response()->json(['ok' => true]);
    }

    /**
     * "Go through the setup again": the marks go, and the wizard opens on this page.
     *
     * The marks are cleared, not only the session key set: otherwise "Skip" on this
     * second pass would leave a wizard finished once and never to be seen again.
     */
    public function again(Request $request): JsonResponse
    {
        $this->refuseSomeoneElse($request);
        $this->wizard->reopen($request->user(), $request);
        $request->session()->put(SetupWizard::SESSION_KEY, true);
        $this->wizard->unskip($request);

        return response()->json(['ok' => true]);
    }

    /** Not on somebody's behalf: the marks are the user's own (review 2026-10-31). */
    private function refuseSomeoneElse(Request $request): void
    {
        abort_if($this->wizard->actedForSomeoneElse($request), 403);
    }
}
