<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Actions;

use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRemoved;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Soft-removes a member from a company and settles their active company.
 *
 * The membership row is kept (audit) but flagged removed. If the removed company
 * was the member's active one, they're moved to their next available company so
 * they don't end up acting as a tenant they no longer belong to.
 */
class RemoveEmployeeAction
{
    public function execute(Company $company, Authenticatable $employee): void
    {
        // Capture whether they were acting as this company BEFORE removing them:
        // once the membership is soft-removed, getCurrentCompanyId() resolves to
        // null (the resolver only returns companies the user still belongs to),
        // so the check has to happen first.
        $wasActive = method_exists($employee, 'getCurrentCompanyId')
            && (string) $employee->getCurrentCompanyId() === (string) $company->getKey();

        $company->removeEmployee($employee);

        // If this was their active company, move them to the next available one
        // so they aren't stranded pointing at a company they no longer belong to.
        if ($wasActive && method_exists($employee, 'setNextAvailableCompany')) {
            $employee->setNextAvailableCompany();
        }

        EmployeeRemoved::dispatch($company, $employee);
    }
}
