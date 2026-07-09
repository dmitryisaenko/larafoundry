<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Listeners;

use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRemovalRequested;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\Concerns\NotifiesLifecycle;

/**
 * Matrix row 4 (member requested removal): in-app to the owner and the member. No
 * email either side.
 */
class SendEmployeeRemovalRequestedNotifications
{
    use NotifiesLifecycle;

    public function handle(EmployeeRemovalRequested $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $company = $event->company;
        $member = $event->employee;
        $owner = $this->ownerOf($company);
        $companyName = (string) $company->name;
        $memberName = $this->displayName($member);

        if ($owner !== null) {
            $ownerLocale = $this->localeFor($owner);
            $this->notifications->system(
                users: [$owner],
                code: 'info',
                titleKey: 'larafoundry::notifications.tenancy.removal_requested.owner.title',
                bodyKey: 'larafoundry::notifications.tenancy.removal_requested.owner.body',
                params: ['member' => $memberName, 'company' => $companyName],
                data: $this->action('larafoundry::notifications.tenancy.action_view_team', '/employees', $ownerLocale),
            );
        }

        $this->notifications->system(
            users: $this->recipients([$member]),
            code: 'info',
            titleKey: 'larafoundry::notifications.tenancy.removal_requested.user.title',
            bodyKey: 'larafoundry::notifications.tenancy.removal_requested.user.body',
            params: ['company' => $companyName],
            data: [],
        );
    }
}
