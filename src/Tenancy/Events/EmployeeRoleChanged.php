<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Events;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an owner changes a member's role set in a company (phase 1, activity
 * completeness — owner-employee matrix row 7).
 *
 * Dispatched only when roles were actually managed AND the set changed (see
 * UpdateEmployeeAction), so a plain identity edit does not log a role change. The
 * causer is the acting owner (resolved from auth by the listener).
 */
class EmployeeRoleChanged
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<int, int>  $roleIds  the member's new role ids in this company
     */
    public function __construct(
        public readonly Company $company,
        public readonly Authenticatable $employee,
        public readonly array $roleIds,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getLogProperties(): array
    {
        return [
            'company_id' => $this->company->getKey(),
            'company_uuid' => $this->company->uuid,
            'employee_id' => $this->employee->getAuthIdentifier(),
            'role_ids' => $this->roleIds,
        ];
    }
}
