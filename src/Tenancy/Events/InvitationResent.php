<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Events;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\CompanyInvitation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an owner re-sends a pending invitation (phase 1, activity
 * completeness — owner-employee matrix row 6).
 *
 * Registered in the activity-log registry; the causer is the acting owner
 * (resolved from auth by the listener). `getLogProperties()` enriches the entry.
 */
class InvitationResent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly CompanyInvitation $invitation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getLogProperties(): array
    {
        return [
            'company_id' => $this->invitation->company_id,
            'company_uuid' => $this->invitation->company?->uuid,
            'invitation_id' => $this->invitation->getKey(),
            'invited_email' => $this->invitation->email,
        ];
    }
}
