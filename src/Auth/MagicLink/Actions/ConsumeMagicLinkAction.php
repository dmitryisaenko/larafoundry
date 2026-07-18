<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\MagicLink\Actions;

use Dmitryisaenko\LaraFoundry\Auth\MagicLink\Models\MagicLoginToken;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Str;

/**
 * Exchanges a clicked magic-link token for a user, or null (phase: magic-link).
 *
 * The token's signature + expiry are already validated by the `signed`
 * middleware; this action closes the single-use guarantee and resolves the
 * identity:
 *
 *  1. ATOMIC CONSUME — one conditional UPDATE flips `consumed_at` from null only
 *     while the token is still within both expiries. Exactly one concurrent
 *     request can affect the row, so a replayed or raced link resolves to null.
 *  2. RESOLVE — mirror of {@see OAuthController::resolveUser} but by email
 *     ownership: an account with this email IS that user (login); otherwise a new
 *     account is created (registration), its email pre-verified because clicking
 *     the emailed link proves ownership.
 *
 * DELIBERATELY UNLIKE OAuth: the reserved super-admin email is NOT refused here.
 * OAuth/registration refuse it because a provider or a stranger merely ASSERTS an
 * email; magic-link PROVES ownership by delivery, so it is the sanctioned (and
 * only) way to authenticate the operator identity. The OAuth reservation is left
 * untouched.
 *
 * Logging in and session regeneration are the controller's job, not the action's.
 */
class ConsumeMagicLinkAction
{
    public function handle(string $token): ?Authenticatable
    {
        if ($token === '') {
            return null;
        }

        $hash = hash('sha256', $token);

        // Atomic single-use: only the request that flips consumed_at from null
        // (while unexpired) proceeds; every other resolves to null.
        $consumed = MagicLoginToken::query()
            ->where('token_hash', $hash)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('absolute_expires_at', '>', now())
            ->update(['consumed_at' => now()]);

        if ($consumed !== 1) {
            return null;
        }

        $row = MagicLoginToken::query()->where('token_hash', $hash)->first();

        if ($row === null) {
            return null;
        }

        return $this->resolveUser($row->email);
    }

    /**
     * An existing account for the (already lowercased) email, else a freshly
     * created one with the email pre-verified.
     */
    protected function resolveUser(string $email): Authenticatable
    {
        $model = $this->userModel();

        $existing = $model::query()->where('email', $email)->first();

        if ($existing !== null) {
            return $existing;
        }

        return $model::query()->create([
            'name' => Str::before($email, '@') ?: $email,
            'email' => $email,
            'email_verified_at' => now(),
            'locale' => config('larafoundry.locale.default', 'en'),
        ]);
    }

    /**
     * @return class-string<User>
     */
    protected function userModel(): string
    {
        return config('auth.providers.users.model', User::class);
    }
}
