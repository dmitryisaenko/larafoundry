<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Jobs;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\CompanyInvitation;
use Dmitryisaenko\LaraFoundry\Tenancy\Notifications\CompanyInvitationNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Delivers a company invitation email off the request cycle.
 *
 * Queued so a slow mail send never blocks the invite request. The invited person
 * has no user account yet, so we notify the raw email address on demand. Runs on
 * whatever queue the host configures — the core assumes only the database driver
 * (no Redis), per the shared-hosting target.
 */
class SendCompanyInvitationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly CompanyInvitation $invitation,
    ) {}

    public function handle(): void
    {
        // Re-check: the invite may have been revoked/accepted before delivery.
        if (! $this->invitation->isActionable()) {
            return;
        }

        Notification::route('mail', $this->invitation->email)
            ->notify(new CompanyInvitationNotification($this->invitation));
    }
}
