<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Events;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a member is (soft-)removed from a company.
 *
 * A hook for activity logging / notifications. RBAC (phase 1.3) may also listen
 * to revoke the removed member's roles in that company.
 */
class EmployeeRemoved
{
    use Dispatchable;

    public function __construct(
        public readonly Company $company,
        public readonly Authenticatable $employee,
    ) {}
}
