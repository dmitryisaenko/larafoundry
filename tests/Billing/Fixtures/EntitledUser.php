<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A minimal authenticatable for the entitlement middleware tests (sub-phase B6).
 *
 * The middleware resolves the active tenant via `$request->user()->getActiveCompany()`;
 * this stub returns a company set in-memory, so the tests need neither the tenancy
 * session machinery nor a database. `isSuperAdmin()` lets one test prove a super-admin
 * is still subject to entitlement (there is no Gate::before bypass — decision D-B6-1).
 */
class EntitledUser extends Authenticatable
{
    protected $guarded = [];

    public ?object $activeCompany = null;

    public bool $superAdmin = false;

    public function getActiveCompany(): ?object
    {
        return $this->activeCompany;
    }

    public function isSuperAdmin(): bool
    {
        return $this->superAdmin;
    }
}
