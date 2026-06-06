<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Profile\Actions\DeleteUserAccount;
use Dmitryisaenko\LaraFoundry\Profile\Http\Resources\ProfileResource;
use Dmitryisaenko\LaraFoundry\Profile\Support\UiSettings;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The profile hub — one page with tabs over the self-service account screens
 * (phase 5.1).
 *
 * Pulls together what the core already had scattered (password, 2FA, PIN,
 * locale, sessions) with the new tabs this phase adds (profile form, avatar,
 * appearance, danger zone). The page itself only renders; each tab posts to its
 * own endpoint (Fortify for name/email/password, the Avatar/UiSettings/Account
 * controllers for the rest).
 */
class ProfileController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $currentSessionId = $request->session()->getId();

        $sessions = method_exists($user, 'sessions')
            ? $user->sessions()->orderByDesc('last_activity')->get()->map(fn ($session) => [
                'id' => $session->id,
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'device_type' => $session->user_device_type,
                'device_name' => $session->user_device_name,
                'os' => $session->user_os,
                'browser' => $session->user_browser,
                'login_method' => $session->login_method,
                'last_activity' => optional($session->last_activity)->toIso8601String(),
                'is_current' => $session->session_id === $currentSessionId,
            ])->values()->all()
            : [];

        return Inertia::render('Profile/ProfileHub', [
            'profile' => new ProfileResource($user),
            'sessions' => $sessions,
            'uiSettings' => UiSettings::resolved($user),
            'uiSettingsSchema' => UiSettings::schema(),
            // The danger zone hides the delete control when the user owns a
            // company (the server still enforces it — this is only UX).
            'canDeleteAccount' => $this->canDeleteAccount($user),
            'pin' => [
                'enabled' => (bool) config('larafoundry.pin.enabled', true),
                'has_pin' => method_exists($user, 'hasPin') && $user->hasPin(),
                'length' => (int) config('larafoundry.pin.length', 4),
            ],
        ]);
    }

    /**
     * Whether the user may delete their account (mirrors the server-side guard
     * in {@see DeleteUserAccount}).
     */
    protected function canDeleteAccount(mixed $user): bool
    {
        if (method_exists($user, 'ownedCompanies') && $user->ownedCompanies()->exists()) {
            return false;
        }

        return $user->getAttribute('password') !== null;
    }
}
