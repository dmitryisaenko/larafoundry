<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Events;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an owner archives their company (phase 7 host request).
 *
 * Registered in the activity-log event registry so archiving is audited (the
 * archive controller emits no other signal). The causer is the acting user,
 * resolved from the session by the activity-log listener — so this carries only
 * the company. `getLogProperties()` enriches the log entry.
 */
class CompanyArchived
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Company $company,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getLogProperties(): array
    {
        return [
            'company_id' => $this->company->getKey(),
            'company_uuid' => $this->company->uuid,
        ];
    }
}
