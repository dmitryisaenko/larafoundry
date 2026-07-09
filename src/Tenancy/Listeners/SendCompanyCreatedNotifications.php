<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Listeners;

use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyCreated;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\Concerns\NotifiesLifecycle;
use Dmitryisaenko\LaraFoundry\Tenancy\Notifications\CompanyCreatedNotification;

/**
 * Matrix row 8 (user created a company): in-app + HTML email (`company_created`)
 * to the owner (the creator). No user side (they are the owner).
 */
class SendCompanyCreatedNotifications
{
    use NotifiesLifecycle;

    public function handle(CompanyCreated $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $owner = $event->owner;
        $companyName = (string) $event->company->name;
        $ownerName = $this->displayName($owner);

        $locale = $this->localeFor($owner);
        $this->notifications->system(
            users: $this->recipients([$owner]),
            code: 'info',
            titleKey: 'larafoundry::notifications.tenancy.company_created.owner.title',
            bodyKey: 'larafoundry::notifications.tenancy.company_created.owner.body',
            params: ['company' => $companyName],
            data: $this->action('larafoundry::notifications.tenancy.action_view_home', '/', $locale),
        );

        if (method_exists($owner, 'notify')) {
            $owner->notify(new CompanyCreatedNotification(
                ownerName: $ownerName,
                companyName: $companyName,
            ));
        }
    }
}
