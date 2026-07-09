<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Listeners;

use Dmitryisaenko\LaraFoundry\Tenancy\Events\InvitationResent;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\Concerns\NotifiesLifecycle;

/**
 * Matrix row 6 (owner re-invited): in-app to the owner only. The invitee's email
 * is the `company_invitation` template re-queued by the resend action itself
 * (row 6 user email = resend the invitation), so it is NOT sent here.
 */
class SendInvitationResentNotifications
{
    use NotifiesLifecycle;

    public function handle(InvitationResent $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $invitation = $event->invitation;
        $company = $invitation->company;

        if ($company === null) {
            return;
        }

        $owner = $this->ownerOf($company);

        if ($owner === null) {
            return;
        }

        $locale = $this->localeFor($owner);
        $this->notifications->system(
            users: [$owner],
            code: 'info',
            titleKey: 'larafoundry::notifications.tenancy.invitation_resent.owner.title',
            bodyKey: 'larafoundry::notifications.tenancy.invitation_resent.owner.body',
            params: ['email' => (string) $invitation->email, 'company' => (string) $company->name],
            data: $this->action('larafoundry::notifications.tenancy.action_view_team', '/employees', $locale),
        );
    }
}
