<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Events;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\CompanyInvitation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an invited user accepts and joins the company (phase 1, activity
 * completeness — owner-employee matrix row 1.1 / 2.1).
 *
 * Registered in the activity-log registry so joins are audited; the causer is the
 * accepting user (resolved from auth by the listener). `getLogProperties()`
 * enriches the entry with the company + invitation.
 */
class InvitationAccepted
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
