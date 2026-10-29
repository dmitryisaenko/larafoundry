<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures;

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;

/**
 * A super-admin acting user for the B7b console suite.
 *
 * Extends {@see PaymentUser} (same `users` table, Notifiable, active-company aware) and
 * adds the `isAdmin()` predicate the core {@see VisitorStatus}
 * reads, so the `larafoundry.admin` route gate and the promo-manage ability both pass.
 * A non-admin actor uses the plain PaymentUser (no isAdmin() → denied).
 */
class AdminUser extends PaymentUser
{
    public function isAdmin(): bool
    {
        // Default true; an explicit is_admin attribute can force a non-admin instance.
        $flag = $this->getAttribute('is_admin');

        return $flag === null ? true : (bool) $flag;
    }
}
