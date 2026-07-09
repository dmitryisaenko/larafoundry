<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Events;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\CompanyInvitation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an owner withdraws (deletes) a pending invitation before it is
 * accepted (phase 1, activity completeness — owner-employee matrix row 5).
 *
 * Dispatched BEFORE the row is deleted so the company + invited address are still
 * readable. The causer is the acting owner (resolved from auth by the listener).
 */
class InvitationWithdrawn
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
