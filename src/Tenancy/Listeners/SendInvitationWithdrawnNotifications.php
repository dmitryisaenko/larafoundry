<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Listeners;

use Dmitryisaenko\LaraFoundry\Tenancy\Events\InvitationWithdrawn;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\Concerns\NotifiesLifecycle;

/**
 * Matrix row 5 (owner withdrew a pending invitation): in-app to the owner only.
 * No email, no invitee notification (they never accepted).
 */
class SendInvitationWithdrawnNotifications
{
    use NotifiesLifecycle;

    public function handle(InvitationWithdrawn $event): void
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
            titleKey: 'larafoundry::notifications.tenancy.invitation_withdrawn.owner.title',
            bodyKey: 'larafoundry::notifications.tenancy.invitation_withdrawn.owner.body',
            params: ['email' => (string) $invitation->email, 'company' => (string) $company->name],
            data: $this->action('larafoundry::notifications.tenancy.action_view_team', '/employees', $locale),
        );
    }
}
