<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Profile\Actions\DeleteUserAccount;
use Dmitryisaenko\LaraFoundry\Profile\Http\Resources\ProfileResource;
use Dmitryisaenko\LaraFoundry\Profile\Support\UiSettings;
use Dmitryisaenko\LaraFoundry\Settings\Support\SettingsRepository;
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
    public function __construct(private readonly SettingsRepository $settings) {}

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
            // The user's own generic settings (e.g. email_notifications), folded
            // into the hub's Preferences tab so there is no separate /settings
            // account page/menu entry. Writes still go to /settings/account
            // (SettingsController@updateAccount); this only SUPPLIES the form.
            // Values are limited to the form-visible keys — consent flags
            // (form=false, written by the phase-5.3 flow) are never shipped here.
            'accountSettings' => $this->accountSettings(),
        ]);
    }

    /**
     * The user-scope generic settings shaped for the hub's folded form: the
     * form-visible schema and only those keys' current values.
     *
     * @return array{schema: array<int, array<string, mixed>>, values: array<string, mixed>}
     */
    protected function accountSettings(): array
    {
        $schema = $this->settings->schemaForScope('user');
        $values = array_intersect_key(
            $this->settings->allForScope('user'),
            array_flip(array_column($schema, 'key')),
        );

        return ['schema' => $schema, 'values' => $values];
    }

    /**
     * Whether the user may delete their account (mirrors the server-side guard
     * in {@see DeleteUserAccount}).
     *
     * Only the owner-with-company guard blocks deletion: an OAuth-only user (no
     * local password) now deletes through the email-confirmation path (phase 5.3,
     * decision D-5.3-11), so a missing password no longer hides the control — the
     * danger zone branches its form on `profile.has_password` instead.
     */
    protected function canDeleteAccount(mixed $user): bool
    {
        return ! (method_exists($user, 'ownedCompanies') && $user->ownedCompanies()->exists());
    }
}
