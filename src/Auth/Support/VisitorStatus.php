<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves an identity-level status for the current (or given) user.
 *
 * This is the auth-phase slice of the legacy `GetVisitorStatusAction`: it knows
 * only about identity — guest, authenticated, verified, blocked, deleted, admin.
 * It deliberately does NOT know about companies; the company-aware statuses
 * ("needs to pick a company", "force-logout by admin IP") are layered on in
 * phase 1.2, which can wrap or extend this resolver.
 *
 * Admin is reported only when the user carries the flag AND (when an admin
 * allow-list email is configured) matches it — a defence-in-depth check that
 * a flipped `is_admin` flag alone cannot grant admin status in production.
 */
class VisitorStatus
{
    public const GUEST = 'guest';

    public const BLOCKED = 'blocked';

    public const DELETED = 'deleted';

    public const ADMIN = 'admin';

    public const VERIFIED = 'verified';

    public const AUTHENTICATED = 'authenticated';

    public function for(?Authenticatable $user = null): string
    {
        $user ??= Auth::user();

        if ($user === null) {
            return self::GUEST;
        }

        if ($this->flag($user, 'isDeleted')) {
            return self::DELETED;
        }

        if ($this->flag($user, 'isBlocked')) {
            return self::BLOCKED;
        }

        if ($this->isAdmin($user)) {
            return self::ADMIN;
        }

        if ($user instanceof MustVerifyEmail && $user->hasVerifiedEmail()) {
            return self::VERIFIED;
        }

        return self::AUTHENTICATED;
    }

    public function isAdmin(?Authenticatable $user = null): bool
    {
        $user ??= Auth::user();

        if ($user === null || ! $this->flag($user, 'isAdmin')) {
            return false;
        }

        if (self::superAdminEmail() === null) {
            return true;
        }

        return method_exists($user, 'getAttribute')
            && self::isSuperAdminEmail($user->getAttribute('email'));
    }

    /**
     * The configured platform super-admin email, or null when none is set.
     *
     * Canonical source is `security.super_admin.email` (phase 1.4). For backward
     * compatibility it falls back to the phase-2.1 `auth.failed_login.admin_email`
     * key, which doubled as the admin allow-list before the super-admin identity
     * was formalised. This single accessor is the one place isAdmin() and the
     * exclusivity guards (registration, OAuth, company ownership) read the email,
     * so the resolution rule cannot drift between them.
     */
    public static function superAdminEmail(): ?string
    {
        foreach ([
            config('larafoundry.security.super_admin.email'),
            config('larafoundry.auth.failed_login.admin_email'),
        ] as $email) {
            if (is_string($email) && $email !== '') {
                return $email;
            }
        }

        return null;
    }

    /**
     * Whether the given email is the reserved platform super-admin email.
     *
     * Comparison is case-insensitive: the core does not lowercase-normalise the
     * identity email column, and DB collations are commonly case-insensitive, so
     * a strict `===` would both let a different-cased variant slip past the
     * reservation guards AND (with a ci collation) strip admin status from the
     * real operator. The one comparison rule lives here so every guard agrees.
     */
    public static function isSuperAdminEmail(?string $email): bool
    {
        $reserved = self::superAdminEmail();

        return $reserved !== null
            && is_string($email)
            && mb_strtolower($email) === mb_strtolower($reserved);
    }

    /**
     * Call a boolean predicate method on the user if the trait provides it.
     */
    protected function flag(Authenticatable $user, string $method): bool
    {
        return method_exists($user, $method) && $user->{$method}() === true;
    }
}
