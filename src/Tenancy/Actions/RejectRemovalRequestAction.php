<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Actions;

use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRemovalRejected;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * An owner rejects a member's pending removal request (phase 2a, matrix row 4.2).
 *
 * The member STAYS in the company: only the two removal-request pivot columns are
 * cleared (mirrors {@see EmployeeController::cancelRemoval}). Idempotent — when
 * there is no pending request this is a no-op and emits no event, so a stale
 * double-submit does not fire a phantom rejection.
 */
class RejectRemovalRequestAction
{
    public function execute(Company $company, Authenticatable $employee): void
    {
        $membership = $company->users()->find($employee->getAuthIdentifier());

        if ($membership === null || $membership->pivot->removal_requested_at === null) {
            return;
        }

        $company->users()->updateExistingPivot($employee->getAuthIdentifier(), [
            'removal_requested_at' => null,
            'removal_requested_by' => null,
        ]);

        EmployeeRemovalRejected::dispatch($company, $employee);
    }
}
