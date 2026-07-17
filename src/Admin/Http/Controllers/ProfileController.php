<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Admin\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Auth\Http\Controllers\SessionController;
use Dmitryisaenko\LaraFoundry\Auth\Models\UserSession;
use Dmitryisaenko\LaraFoundry\Http\Middleware\EnsureAdminOtpVerified;
use Dmitryisaenko\LaraFoundry\Media\Actions\StoreUploadedFileAction;
use Dmitryisaenko\LaraFoundry\Profile\Http\Controllers\AvatarController;
use Dmitryisaenko\LaraFoundry\Profile\Http\Requests\UpdateAvatarRequest;
use Dmitryisaenko\LaraFoundry\Profile\Http\Resources\ProfileResource;
use Dmitryisaenko\LaraFoundry\Profile\Support\UiSettings;
use Dmitryisaenko\LaraFoundry\Settings\Http\Controllers\SettingsController;
use Dmitryisaenko\LaraFoundry\Settings\Http\Requests\UpdateSettingRequest;
use Dmitryisaenko\LaraFoundry\Settings\Support\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The operator's own profile hub (in-console), super-admin only.
 *
 * The operator is confined to `admin.*` routes (RedirectSuperAdminToConsole), so
 * they cannot reach the tenant profile screens (`/profile/*`, `/user/*`,
 * `/settings/*`, `/auth/sessions/*`). This controller re-exposes the SAME
 * self-service surface under `admin.profile.*`: the WRITE paths delegate to the
 * very controllers the tenant profile uses — avatar → {@see AvatarController},
 * account settings → {@see SettingsController}, sessions → {@see SessionController}
 * — so no write logic is duplicated. The two small read-shaping helpers below
 * (the session-list map and {@see accountSettings}) mirror the tenant
 * {@see \Dmitryisaenko\LaraFoundry\Profile\Http\Controllers\ProfileController}.
 * The security tab (2FA / PIN / password) keeps using the existing
 * `admin.security.*` endpoints (which carry the OTP step-up gating).
 *
 * WHY it lives OUTSIDE the OTP step-up gate (like `admin.security.show`): the
 * hub's Security tab IS the enrolment surface, so gating the page would loop an
 * un-enrolled operator (the gate redirects here to enrol). The destructive 2FA
 * actions stay behind the gate on their own `admin.security.*` routes.
 *
 * The operator's EMAIL is deliberately immutable here: it is the reserved
 * super-admin identity (VisitorStatus matches it), so changing it could lock the
 * operator out of `/admin`. `updateInformation` validates and saves the profile
 * fields only — never the email.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * Render the operator profile hub with the union of profile + security state.
     */
    public function show(Request $request): Response
    {
        $user = $request->user();
        $currentSessionId = $request->session()->getId();

        // 2FA / step-up state — the same derivation the operator security actions
        // rely on, so the Security tab behaves as the old standalone page did.
        $hasSecret = $user->two_factor_secret !== null;
        $confirmed = $hasSecret && $user->two_factor_confirmed_at !== null;
        $enrolling = $hasSecret && ! $confirmed;
        $steppedUp = $request->session()->get(EnsureAdminOtpVerified::SESSION_KEY) === true;

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

        return Inertia::render('Admin/Profile/Hub', [
            'profile' => new ProfileResource($user),
            'sessions' => $sessions,
            'uiSettings' => UiSettings::resolved($user),
            'uiSettingsSchema' => UiSettings::schema(),
            'accountSettings' => $this->accountSettings(),
            'pin' => [
                'enabled' => (bool) config('larafoundry.pin.enabled', true),
                'has_pin' => method_exists($user, 'hasPin') && $user->hasPin(),
                'length' => (int) config('larafoundry.pin.length', 4),
            ],
            // Security tab state (2FA enrolment + step-up gating).
            'two_factor_enabled' => $confirmed,
            'two_factor_setup' => $enrolling
                ? [
                    'svg' => $user->twoFactorQrCodeSvg(),
                    'recovery_codes' => $user->recoveryCodes(),
                ]
                : null,
            'recovery_codes' => ($confirmed && $steppedUp) ? $user->recoveryCodes() : null,
            'can_manage_two_factor' => $steppedUp,
            // Which tab to open on load — set by the redirect from the old
            // `admin.security.show` and by the Security tab's server redirects.
            'initialTab' => $this->initialTab($request),
        ]);
    }

    /**
     * Update the operator's profile fields (name/phone/country/…). The email is
     * intentionally NOT accepted — see the class docblock.
     */
    public function updateInformation(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'lastname' => ['nullable', 'string', 'max:255'],
            'middlename' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'sex' => ['nullable', 'string', 'max:1'],
            'birth_date' => ['nullable', 'date'],
        ])->validate();

        $user->fill($data)->save();

        return back()->with('status', __('larafoundry::profile.updated'));
    }

    /**
     * Upload the operator's avatar. Delegates to the tenant AvatarController —
     * same Media-seam storage + resize, operating on $request->user().
     */
    public function updateAvatar(UpdateAvatarRequest $request, StoreUploadedFileAction $storeFile): RedirectResponse
    {
        return (new AvatarController)->update($request, $storeFile);
    }

    /**
     * Remove the operator's avatar (reverts to the initials placeholder).
     */
    public function destroyAvatar(Request $request): RedirectResponse
    {
        return (new AvatarController)->destroy($request);
    }

    /**
     * Save one user-scope account setting. Delegates to the tenant
     * SettingsController (user scope resolves to the caller — the operator).
     */
    public function updateAccount(UpdateSettingRequest $request): RedirectResponse
    {
        return app(SettingsController::class)->updateAccount($request);
    }

    /**
     * Revoke one of the operator's own tracked sessions. Delegates to the tenant
     * SessionController (which enforces the ownership IDOR check).
     */
    public function destroySession(Request $request, UserSession $session): RedirectResponse
    {
        return (new SessionController)->destroy($request, $session);
    }

    /**
     * Revoke every other device of the operator.
     */
    public function destroyOtherSessions(Request $request): RedirectResponse
    {
        return (new SessionController)->destroyOthers($request);
    }

    /**
     * The user-scope generic settings shaped for the hub's folded form (mirrors
     * the tenant ProfileController): the form-visible schema and its values.
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
     * Resolve the initial tab from the `?tab=` query, defaulting to 'profile'.
     */
    protected function initialTab(Request $request): string
    {
        $tab = (string) $request->query('tab', 'profile');

        return in_array($tab, ['profile', 'avatar', 'security', 'sessions', 'appearance'], true)
            ? $tab
            : 'profile';
    }
}
