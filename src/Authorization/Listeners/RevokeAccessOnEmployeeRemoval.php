<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Authorization\Listeners;

use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRemoved;

/**
 * Strip a removed member's RBAC grants in the company they left (phase 1.3).
 *
 * Listens on the EmployeeRemoved seam (its docblock anticipates this). Detaches
 * the member's role assignments and individual permission overrides scoped to that
 * company, so:
 *   - a removed member carries no lingering authority if they are ever re-resolved
 *     in that company (security hygiene), and
 *   - a custom role held only by removed members becomes deletable again
 *     (canBeDeleted counts holders, which would otherwise never reach zero).
 *
 * Global roles (company_id = null, e.g. `authenticated`) are untouched — leaving a
 * company never changes a user's global identity.
 */
class RevokeAccessOnEmployeeRemoval
{
    public function handle(EmployeeRemoved $event): void
    {
        $employee = $event->employee;
        $companyId = $event->company->getKey();

        if (method_exists($employee, 'roles')) {
            $employee->roles()->wherePivot('company_id', $companyId)->detach();
        }

        if (method_exists($employee, 'permissions')) {
            $employee->permissions()->wherePivot('company_id', $companyId)->detach();
        }
    }
}
