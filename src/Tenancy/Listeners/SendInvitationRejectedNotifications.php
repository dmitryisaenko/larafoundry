<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Listeners;

use Dmitryisaenko\LaraFoundry\Tenancy\Events\InvitationRejected;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\Concerns\NotifiesLifecycle;
use Dmitryisaenko\LaraFoundry\Tenancy\Notifications\InvitationRejectedOwnerNotification;

/**
 * Matrix row 2.2 (invitee declined): in-app + HTML email to the owner
 * (`invitation_rejected_owner`), plus in-app to the declining user.
 */
class SendInvitationRejectedNotifications
{
    use NotifiesLifecycle;

    public function handle(InvitationRejected $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $invitation = $event->invitation;
        $company = $invitation->company;
        $user = $event->user;

        if ($company === null) {
            return;
        }

        $owner = $this->ownerOf($company);
        $companyName = (string) $company->name;
        $invitedEmail = (string) $invitation->email;

        if ($owner !== null) {
            $ownerLocale = $this->localeFor($owner);
            $this->notifications->system(
                users: [$owner],
                code: 'info',
                titleKey: 'larafoundry::notifications.tenancy.rejected.owner.title',
                bodyKey: 'larafoundry::notifications.tenancy.rejected.owner.body',
                params: ['email' => $invitedEmail, 'company' => $companyName],
                data: $this->action('larafoundry::notifications.tenancy.action_view_team', '/employees', $ownerLocale),
            );

            $owner->notify(new InvitationRejectedOwnerNotification(
                ownerName: $this->displayName($owner),
                invitedEmail: $invitedEmail,
                companyName: $companyName,
            ));
        }

        $this->notifications->system(
            users: $this->recipients([$user]),
            code: 'info',
            titleKey: 'larafoundry::notifications.tenancy.rejected.user.title',
            bodyKey: 'larafoundry::notifications.tenancy.rejected.user.body',
            params: ['company' => $companyName],
            data: [],
        );
    }
}
