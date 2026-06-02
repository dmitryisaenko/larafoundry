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

        $allowed = config('larafoundry.auth.failed_login.admin_email');

        if (is_string($allowed) && $allowed !== '') {
            return method_exists($user, 'getAttribute')
                && $user->getAttribute('email') === $allowed;
        }

        return true;
    }

    /**
     * Call a boolean predicate method on the user if the trait provides it.
     */
    protected function flag(Authenticatable $user, string $method): bool
    {
        return method_exists($user, $method) && $user->{$method}() === true;
    }
}
