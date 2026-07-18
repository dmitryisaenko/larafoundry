<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\MagicLink\Actions;

use Dmitryisaenko\LaraFoundry\Auth\MagicLink\Models\MagicLoginToken;
use Dmitryisaenko\LaraFoundry\Auth\MagicLink\Notifications\MagicLoginLinkNotification;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Issues a passwordless sign-in link for an email (phase: magic-link).
 *
 * ANTI-ENUMERATION: the work is identical whether or not an account owns the
 * address — we always mint a token and mail the link. There is no "user lookup"
 * here at all; resolution/creation happens only when the link is clicked
 * ({@see ConsumeMagicLinkAction}). The caller returns one neutral response
 * regardless, so a requester cannot learn whether an email is registered.
 *
 * The plaintext secret lives ONLY in the emailed link (a temporary signed URL);
 * the database keeps its SHA-256 hash. A sliding TTL and an absolute cap bound
 * the token's life, and (when configured) a new request retires the address's
 * earlier unused tokens so only the latest link works.
 */
class RequestMagicLinkAction
{
    public function handle(string $email, ?string $ip = null): void
    {
        $email = Str::lower(trim($email));

        // A fresh request supersedes the address's earlier unused links.
        if ((bool) config('larafoundry.auth.magic_link.invalidate_previous', true)) {
            MagicLoginToken::query()
                ->forEmail($email)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);
        }

        $plain = Str::random(64);
        $ttlMinutes = $this->ttlMinutes();

        MagicLoginToken::create([
            'email' => $email,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addMinutes($ttlMinutes),
            'absolute_expires_at' => now()->addMinutes($this->absoluteTtlMinutes()),
            'requested_ip' => $ip,
        ]);

        // A temporary signed URL so a tampered or stale link fails the `signed`
        // middleware before any DB work — its expiry matches the sliding TTL.
        $url = URL::temporarySignedRoute(
            'magic-link.verify',
            now()->addMinutes($ttlMinutes),
            ['token' => $plain],
        );

        // The address may own no account yet, so notify it on demand (the same
        // idiom as the company invitation). Carry the requester's active locale so
        // the mail matches the language they are using right now.
        Notification::route('mail', $email)->notify(
            new MagicLoginLinkNotification($url, App::getLocale()),
        );
    }

    protected function ttlMinutes(): int
    {
        return (int) config('larafoundry.auth.magic_link.ttl_minutes', 15);
    }

    protected function absoluteTtlMinutes(): int
    {
        return (int) config('larafoundry.auth.magic_link.absolute_ttl_minutes', 30);
    }
}
