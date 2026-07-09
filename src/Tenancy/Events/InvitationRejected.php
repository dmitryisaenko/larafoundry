<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Events;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\CompanyInvitation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an invited user declines a company invitation (phase 1, activity
 * completeness — owner-employee matrix row 2.2).
 *
 * Registered in the activity-log registry; the causer is the rejecting user
 * (resolved from auth by the listener). `getLogProperties()` enriches the entry.
 */
class InvitationRejected
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly CompanyInvitation $invitation,
        public readonly Authenticatable $user,
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
