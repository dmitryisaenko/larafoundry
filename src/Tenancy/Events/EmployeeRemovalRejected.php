<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Events;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an owner rejects a member's pending removal request, keeping the
 * member in the company (phase 2a, owner-employee matrix row 4.2).
 *
 * The acting owner is the causer (resolved from auth by the activity-log
 * listener). `getLogProperties()` enriches the entry with the company + employee,
 * matching its sibling removal events so an audit query by company_uuid captures
 * rejections too.
 */
class EmployeeRemovalRejected
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
