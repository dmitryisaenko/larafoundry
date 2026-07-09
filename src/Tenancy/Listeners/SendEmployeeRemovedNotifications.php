<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Listeners;

use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRemoved;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\Concerns\NotifiesLifecycle;
use Dmitryisaenko\LaraFoundry\Tenancy\Notifications\EmployeeRemovedNotification;

/**
 * Matrix rows 3 and 4.1 (member removed): in-app to the owner and the removed
 * member for both. Row 4.1 (the removal satisfied the member's own pending
 * request, carried as `wasRequested`) additionally emails the member the
 * `employee_removed_notification` template; a plain owner-initiated removal
 * (row 3) sends no member email.
 */
class SendEmployeeRemovedNotifications
{
    use NotifiesLifecycle;

    public function handle(EmployeeRemoved $event): void
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
                titleKey: 'larafoundry::notifications.tenancy.removed.owner.title',
                bodyKey: 'larafoundry::notifications.tenancy.removed.owner.body',
                params: ['member' => $memberName, 'company' => $companyName],
                data: $this->action('larafoundry::notifications.tenancy.action_view_team', '/employees', $ownerLocale),
            );
        }

        $this->notifications->system(
            users: $this->recipients([$member]),
            code: 'info',
            titleKey: 'larafoundry::notifications.tenancy.removed.user.title',
            bodyKey: 'larafoundry::notifications.tenancy.removed.user.body',
            params: ['company' => $companyName],
            data: [],
        );

        // Row 4.1 only: an owner-approved leave emails the member.
        if ($event->wasRequested && method_exists($member, 'notify')) {
            $member->notify(new EmployeeRemovedNotification(
                memberName: $memberName,
                companyName: $companyName,
            ));
        }
    }
}
