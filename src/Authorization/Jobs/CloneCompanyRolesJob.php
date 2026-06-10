<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Authorization\Jobs;

use Dmitryisaenko\LaraFoundry\Authorization\Actions\CloneCompanyRolesAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\App;

/**
 * Clone the catalog's role templates into a freshly created company (phase 1.3).
 *
 * Dispatched from the CompanyCreated listener (decision D1.3-c). Queued because
 * cloning is not on the owner's critical path: the owner already has full access
 * via the `is_owner` flag bypass, so a brief window before the cloned roles exist
 * only affects employees who are invited LATER — and the wizard's synchronous
 * ensure (the same {@see CloneCompanyRolesAction}) covers the one case that needs
 * roles immediately, the role-at-invite dropdown.
 *
 * The actual clone (idempotency, locking, transaction) lives in the action so the
 * async and synchronous paths share one implementation; this job is the thin
 * queued wrapper.
 */
class CloneCompanyRolesJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Company $company) {}

    public function handle(): void
    {
        App::make(CloneCompanyRolesAction::class)->execute($this->company);
    }
}
