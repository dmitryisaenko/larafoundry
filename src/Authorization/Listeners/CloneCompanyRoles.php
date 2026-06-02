<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Authorization\Listeners;

use Dmitryisaenko\LaraFoundry\Authorization\Jobs\CloneCompanyRolesJob;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyCreated;

/**
 * On company creation, queue the cloning of role templates into it (phase 1.3).
 *
 * This is the seam phase 1.2 left open (the CompanyCreated event) — it closes the
 * "clone default roles on creation" debt. The work itself is deferred to a queued
 * job (decision D1.3-c); see {@see CloneCompanyRolesJob} for why that is safe.
 */
class CloneCompanyRoles
{
    public function handle(CompanyCreated $event): void
    {
        CloneCompanyRolesJob::dispatch($event->company);
    }
}
