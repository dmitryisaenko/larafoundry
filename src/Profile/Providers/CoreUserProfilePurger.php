<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Providers;

use Dmitryisaenko\LaraFoundry\Media\Contracts\MediaStorage;
use Dmitryisaenko\LaraFoundry\Profile\Contracts\PurgesUserData;
use Dmitryisaenko\LaraFoundry\Profile\Http\Controllers\AvatarController;
use Dmitryisaenko\LaraFoundry\Settings\Support\SettingsRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Erases the identity the core owns — the baseline section of an account erasure
 * (phase 5.3, the symmetric mirror of {@see CoreUserProfileExporter}).
 *
 * The user row is NEVER hard-deleted: the activity log and other modules
 * reference it by id, so deleting it would break referential integrity and the
 * audit trail (decision D-5.3-9). Instead it is anonymised — every PII attribute
 * is replaced with a deterministic placeholder or nulled, every secret is wiped,
 * the avatar file is removed, personal-access tokens are revoked, tracked
 * sessions are evicted, and the user's settings rows (UI prefs, consent state)
 * are forgotten. The deterministic placeholders (`deleted-user-{id}`) keep the
 * operation idempotent: re-running produces the same anonymised row.
 *
 * What this deliberately does NOT touch: the activity log. It is the audit /
 * consent-proof record (decision D-5.3-8) and stores no raw PII of its own
 * (subject/causer are ids pointing at this now-anonymised row); it has its own
 * retention window (`larafoundry-activitylog.retention_days`).
 */
class CoreUserProfilePurger implements PurgesUserData
{
    public function __construct(
        protected MediaStorage $media,
        protected SettingsRepository $settings,
    ) {}

    public function purgeFor(Authenticatable $user): void
    {
        if (! $user instanceof Model) {
            return;
        }

        $id = $user->getKey();
        $previousAvatar = (string) ($user->getAttribute('avatar') ?? '');

        // Anonymise the identity row. `name`/`email` keep a deterministic,
        // non-colliding placeholder (name is required for initials avatars; email
        // carries a unique index); everything else is nulled. The payload is
        // filtered to columns that actually exist so a host missing the optional
        // Fortify two-factor columns is tolerated (the trait does not own them).
        $payload = [
            'name' => 'deleted-user-'.$id,
            'lastname' => null,
            'middlename' => null,
            'email' => 'deleted-'.$id.'@'.$this->anonymizedEmailDomain(),
            'email_verified_at' => null,
            'phone' => null,
            'phone_verified_at' => null,
            'country' => null,
            'sex' => null,
            'birth_date' => null,
            'locale' => null,
            'avatar' => null,
            'ui_settings' => null,
            // Secrets: nulled / revoked so the anonymised account cannot be
            // signed into by any factor.
            'password' => null,
            'remember_token' => null,
            'pin_code' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'provider_id' => null,
            'provider_name' => null,
            'provider_token' => null,
            'provider_refresh_token' => null,
        ];

        $columns = Schema::getColumnListing($user->getTable());
        $payload = array_intersect_key($payload, array_flip($columns));

        $user->forceFill($payload)->save();

        // Evict tracked sessions and, on the database driver, the framework
        // session rows that actually hold the login.
        if (method_exists($user, 'sessions')) {
            $user->sessions()->delete();
        }

        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getAuthIdentifier())
                ->delete();
        }

        // Revoke API access (Sanctum personal-access tokens).
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }

        // Forget the user's settings rows (UI prefs + consent state). The consent
        // PROOF lives in the activity log (D-5.3-8); this is only the mutable state.
        $this->settings->forgetAllForUser($user->getAuthIdentifier());

        // Delete the stored avatar file last (idempotent: a missing file no-ops).
        $this->deleteStoredAvatar($previousAvatar);
    }

    public function key(): string
    {
        return 'profile';
    }

    public function priority(): int
    {
        return 0;
    }

    /**
     * The domain placeholder anonymised emails are minted under. Defaults to the
     * RFC 2606 reserved `.invalid` TLD, which can never be a deliverable address.
     */
    protected function anonymizedEmailDomain(): string
    {
        $domain = config('larafoundry-legal.erasure.anonymized_email_domain', 'deleted.invalid');

        return is_string($domain) && $domain !== '' ? $domain : 'deleted.invalid';
    }

    /**
     * Delete a previously stored avatar file, skipping external URLs.
     *
     * Mirrors {@see AvatarController::deleteStored()}:
     * the column can hold a stored relative path OR an absolute URL set by an
     * OAuth provider, and only the former is ours to delete.
     */
    protected function deleteStoredAvatar(string $value): void
    {
        $value = trim($value);

        if ($value === ''
            || str_starts_with($value, '//')
            || str_starts_with($value, 'data:')
            || str_contains($value, '://')) {
            return;
        }

        $this->media->delete($value);
    }
}
