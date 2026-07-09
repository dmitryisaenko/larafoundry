<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Listeners;

use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRoleChanged;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\Concerns\NotifiesLifecycle;

/**
 * Matrix row 7 (owner changed a member's roles): in-app to the affected member
 * only. No owner notification, no email.
 */
class SendEmployeeRoleChangedNotifications
{
    use NotifiesLifecycle;

    public function handle(EmployeeRoleChanged $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $company = $event->company;
        $member = $event->employee;

        $this->notifications->system(
            users: $this->recipients([$member]),
            code: 'info',
            titleKey: 'larafoundry::notifications.tenancy.role_changed.user.title',
            bodyKey: 'larafoundry::notifications.tenancy.role_changed.user.body',
            params: ['company' => (string) $company->name],
            data: [],
        );
    }
}
