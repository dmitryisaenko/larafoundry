<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Concerns;

use Dmitryisaenko\LaraFoundry\Auth\Models\UserSession;
use Dmitryisaenko\LaraFoundry\Auth\Support\DeviceFingerprint;
use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Dmitryisaenko\LaraFoundry\Contracts\HasLocalePreference;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Identity behaviour for a LaraFoundry user model.
 *
 * This is the auth-phase (1.1) slice only: it carries identity, locale, OAuth
 * provider linkage, blocking state and session tracking. It deliberately does
 * NOT pull in company/tenancy or role/permission behaviour — those arrive as
 * their own traits in later phases (1.2 tenancy, 1.3 RBAC) so the host can
 * compose them onto one model without this trait owning concerns it shouldn't.
 *
 * The host model keeps `extends Authenticatable` for itself; per the Sanctum/
 * Fortify idiom we ship behaviour as a trait rather than a base class.
 *
 * Expected to be used on a model that also satisfies
 * {@see MustVerifyEmail} and
 * {@see HasLocalePreference}.
 */
trait IsLaraFoundryUser
{
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * Identity attributes safe to mass-assign.
     *
     * Excludes blocking columns (`user_blocked_at`, `user_deleted_at`,
     * `block_code`) and `is_admin` on purpose — those are privilege/state
     * transitions and must be set explicitly, never through request input.
     *
     * @return array<int, string>
     */
    public function laraFoundryFillable(): array
    {
        return [
            'name',
            'lastname',
            'middlename',
            'phone',
            'email',
            'password',
            'avatar',
            'provider_id',
            'provider_name',
            'provider_token',
            'provider_refresh_token',
            'country',
            'sex',
            'locale',
            'birth_date',
            'ui_settings',
        ];
    }

    /**
     * Attribute casts contributed by the identity layer.
     *
     * The host merges these into its own `casts()`. `password => hashed`
     * replaces the legacy hand-rolled mutator: Laravel hashes on set and skips
     * re-hashing an already-hashed value, which the old `Hash::make` mutator
     * did not.
     *
     * @return array<string, string>
     */
    public function laraFoundryCasts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'birth_date' => 'date:Y-m-d',
            'user_blocked_at' => 'datetime',
            'user_deleted_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'ui_settings' => 'array',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * Attributes that should always be hidden from serialization.
     *
     * @return array<int, string>
     */
    public function laraFoundryHidden(): array
    {
        return [
            'password',
            'remember_token',
            'provider_token',
            'provider_refresh_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
        ];
    }

    /**
     * The tracked sessions (one row per device/login) for this user.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    /**
     * Record (or refresh) the tracked session row for the current device.
     *
     * The single write path for session tracking, shared by every login flow
     * (native, OAuth, register, remember-me, 2FA) via the Login-event listener.
     * Keyed to the framework session id, so repeated calls within one device
     * update in place rather than duplicating. Also stamps the user's
     * login/activity timestamps.
     */
    public function recordSession(
        string $sessionId,
        DeviceFingerprint $device,
        string $loginMethod = 'native',
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        $this->sessions()->updateOrCreate(
            ['session_id' => $sessionId],
            array_merge([
                'login_method' => $loginMethod,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'last_activity' => now(),
            ], $device->toSessionAttributes()),
        );

        $this->forceFill([
            'last_login_at' => now(),
            'last_activity_at' => now(),
        ])->save();
    }

    /**
     * The user's preferred locale, or null when none is stored.
     *
     * Satisfies {@see HasLocalePreference}.
     */
    public function preferredLocale(): ?string
    {
        $locale = $this->getAttribute('locale');

        return is_string($locale) && $locale !== '' ? $locale : null;
    }

    /**
     * Persist a newly resolved locale on the user.
     */
    public function setPreferredLocale(string $locale): void
    {
        $this->forceFill(['locale' => $locale])->save();
    }

    /**
     * Whether this user currently holds the platform-admin flag.
     *
     * This is the raw flag only. A full visitor-status decision (blocked /
     * deleted / admin-by-IP) lives in
     * {@see VisitorStatus}; company-aware
     * status arrives in phase 1.2.
     */
    public function isAdmin(): bool
    {
        return (bool) $this->getAttribute('is_admin');
    }

    /**
     * Whether the account is currently blocked.
     */
    public function isBlocked(): bool
    {
        return $this->getAttribute('user_blocked_at') !== null;
    }

    /**
     * Whether the account is soft-deleted via the identity columns.
     *
     * Note: this is the legacy `user_deleted_at` identity column, independent of
     * Eloquent SoftDeletes; the host may layer SoftDeletes separately.
     */
    public function isDeleted(): bool
    {
        return $this->getAttribute('user_deleted_at') !== null;
    }

    /**
     * Whether this user authenticates only through an OAuth provider
     * (registered via Socialite and never set a local password).
     */
    public function isOauthOnly(): bool
    {
        return $this->getAttribute('password') === null
            && $this->getAttribute('provider_name') !== null;
    }
}
