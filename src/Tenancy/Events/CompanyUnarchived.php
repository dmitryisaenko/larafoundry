<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Events;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an owner unarchives (restores) their company (phase 7 host request).
 *
 * The mirror of {@see CompanyArchived}: registered in the activity-log registry,
 * carries only the company (the causer is the acting user, resolved from the
 * session by the listener). `getLogProperties()` enriches the log entry.
 */
class CompanyUnarchived
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
