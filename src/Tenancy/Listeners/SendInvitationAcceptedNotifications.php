<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Listeners;

use Dmitryisaenko\LaraFoundry\Tenancy\Events\InvitationAccepted;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\Concerns\NotifiesLifecycle;
use Dmitryisaenko\LaraFoundry\Tenancy\Notifications\EmployeeJoinedConfirmationNotification;
use Dmitryisaenko\LaraFoundry\Tenancy\Notifications\InvitationAcceptedOwnerNotification;

/**
 * Matrix rows 1.1 / 2.1 (invitee accepted): in-app + HTML email to the owner
 * (`invitation_accepted_owner`) on BOTH rows, plus in-app to the joined member on
 * both rows.
 *
 * The member's `employee_joined_confirmation` email is UserEmail on row 1.1 only
 * (the invitee who registered as part of accepting) and blank on row 2.1 (an
 * already-registered user), so it is gated on `$event->wasNewAccount`.
 */
class SendInvitationAcceptedNotifications
{
    use NotifiesLifecycle;

    public function handle(InvitationAccepted $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $invitation = $event->invitation;
        $company = $invitation->company;
        $member = $event->user;

        if ($company === null) {
            return;
        }

        $owner = $this->ownerOf($company);
        $companyName = (string) $company->name;
        $memberName = $this->displayName($member);

        if ($owner !== null) {
            $ownerLocale = $this->localeFor($owner);
            $this->notifications->system(
                users: [$owner],
                code: 'info',
                titleKey: 'larafoundry::notifications.tenancy.accepted.owner.title',
                bodyKey: 'larafoundry::notifications.tenancy.accepted.owner.body',
                params: ['member' => $memberName, 'company' => $companyName],
                data: $this->action('larafoundry::notifications.tenancy.action_view_team', '/employees', $ownerLocale),
            );

            $owner->notify(new InvitationAcceptedOwnerNotification(
                ownerName: $this->displayName($owner),
                memberName: $memberName,
                companyName: $companyName,
            ));
        }

        $memberLocale = $this->localeFor($member);
        $this->notifications->system(
            users: $this->recipients([$member]),
            code: 'info',
            titleKey: 'larafoundry::notifications.tenancy.accepted.user.title',
            bodyKey: 'larafoundry::notifications.tenancy.accepted.user.body',
            params: ['company' => $companyName],
            data: $this->action('larafoundry::notifications.tenancy.action_view_home', '/', $memberLocale),
        );

        // UserEmail on row 1.1 only: a freshly registered invitee gets the
        // joined-confirmation email; an already-registered user (row 2.1) does not.
        if ($event->wasNewAccount && method_exists($member, 'notify')) {
            $member->notify(new EmployeeJoinedConfirmationNotification(
                memberName: $memberName,
                companyName: $companyName,
            ));
        }
    }
}
