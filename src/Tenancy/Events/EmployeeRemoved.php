<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Events;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a member is (soft-)removed from a company.
 *
 * A hook for activity logging / notifications. RBAC (phase 1.3) may also listen
 * to revoke the removed member's roles in that company. `getLogProperties()`
 * enriches the audit entry with the company + employee (matching its sibling
 * removal events so an audit query by company_uuid captures removals too).
 */
class EmployeeRemoved
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  bool  $wasRequested  whether the member had a pending removal request
     *                              at removal time (an owner-approved leave, matrix
     *                              row 4.1) versus an owner-initiated removal (row 3).
     *                              Defaults false so existing dispatch sites and any
     *                              host listener stay backward-compatible.
     */
    public function __construct(
        public readonly Company $company,
        public readonly Authenticatable $employee,
        public readonly bool $wasRequested = false,
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
