<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Events;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a member asks to be removed from a company (phase 1, activity
 * completeness — owner-employee matrix row 4).
 *
 * The requesting member is both subject and causer (resolved from auth by the
 * listener). `getLogProperties()` enriches the entry with the company + employee.
 */
class EmployeeRemovalRequested
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Company $company,
        public readonly Authenticatable $employee,
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
        ];
    }
}
