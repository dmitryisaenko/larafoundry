<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Events;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a company is created and its owner attached.
 *
 * This is the phase-1.3 hook point (decision D1.2-f): RBAC will listen here to
 * clone the default role set into the new company. In phase 1.2 nothing listens
 * yet — ownership is the `is_owner` pivot flag alone.
 */
class CompanyCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Company $company,
        public readonly Authenticatable $owner,
    ) {}
}
