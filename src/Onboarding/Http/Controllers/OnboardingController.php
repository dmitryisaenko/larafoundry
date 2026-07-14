<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Onboarding\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Settings\Support\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * The onboarding checklist's dismiss endpoint (phase 5.4).
 *
 * The checklist is gentle: a user can hide it at any time, forever. The flag is a
 * user-scoped setting written HERE — not through the generic SettingsController —
 * because `onboarding.dismissed` is declared `form => false`, so the self-service
 * settings form would reject it. The repository's `set()` resolves the user from
 * auth and bypasses the form gate, so the write is both scoped to the caller and
 * allowed.
 */
class OnboardingController extends Controller
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * Persist the current user's "checklist hidden" flag and re-render.
     *
     * Idempotent — dismissing an already-dismissed checklist just writes true
     * again. `back()` re-renders the Inertia response, so the `onboarding` shared
     * prop recomputes to the dismissed payload and the component disappears.
     */
    public function dismiss(): RedirectResponse
    {
        // User scope resolves the scope id from auth, so a user can only ever
        // dismiss their own checklist.
        $this->settings->set('onboarding.dismissed', true);

        return back()->with('status', __('larafoundry::onboarding.dismissed'));
    }
}
