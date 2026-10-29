<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A Notifiable user on the test `users` table (sub-phase B7a).
 *
 * Used two ways: as a real owner / recipient for the payment-notification tests, and as
 * the acting user for the enforcement tests — where `activeCompany` is set in-memory
 * (mirroring the host's getActiveCompany()) and `isBlocked()` mirrors the core
 * IsLaraFoundryUser trait so an owner-ban can be exercised.
 *
 * It also mirrors that trait's `withoutSuperAdmin()` LIST scope: the core's dashboard
 * aggregates call it on the configured auth model, so a fixture without it breaks the
 * B9 widget test on a bare `Builder`.
 */
class PaymentUser extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    public ?object $activeCompany = null;

    public function getActiveCompany(): ?object
    {
        return $this->activeCompany;
    }

    public function isBlocked(): bool
    {
        return $this->getAttribute('user_blocked_at') !== null;
    }

    /**
     * Keep the operator account out of list/count queries — the core's local scope,
     * copied here rather than pulling in the whole IsLaraFoundryUser trait.
     */
    public function scopeWithoutSuperAdmin(Builder $query): Builder
    {
        if (! config('larafoundry.admin.exclude_super_admin_from_lists', true)) {
            return $query;
        }

        return $query->where(function (Builder $inner): void {
            $inner->where('is_admin', false)->orWhereNull('is_admin');
        });
    }
}
