<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Events;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\CompanyInvitation;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when an employee invitation is created and queued for delivery.
 *
 * A hook for activity logging (phase 4) and analytics. The notification itself
 * is dispatched separately, not from this event.
 */
class CompanyInvitationSent
{
    use Dispatchable;

    public function __construct(
        public readonly CompanyInvitation $invitation,
    ) {}
}
