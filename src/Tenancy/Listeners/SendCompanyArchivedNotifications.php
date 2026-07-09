<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Listeners;

use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyArchived;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\Concerns\NotifiesLifecycle;
use Dmitryisaenko\LaraFoundry\Tenancy\Notifications\CompanyDeletedConfirmationNotification;

/**
 * Matrix row 9 (owner "deleted" = archived their company): in-app + HTML email
 * (`company_deleted_confirmation`, archive wording) to the owner. Archive, not
 * hard delete, so the copy says the data is kept and recoverable.
 */
class SendCompanyArchivedNotifications
{
    use NotifiesLifecycle;

    public function handle(CompanyArchived $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $company = $event->company;
        $owner = $this->ownerOf($company);

        if ($owner === null) {
            return;
        }

        $companyName = (string) $company->name;
        $ownerName = $this->displayName($owner);

        $this->notifications->system(
            users: [$owner],
            code: 'info',
            titleKey: 'larafoundry::notifications.tenancy.company_archived.owner.title',
            bodyKey: 'larafoundry::notifications.tenancy.company_archived.owner.body',
            params: ['company' => $companyName],
            data: [],
        );

        $owner->notify(new CompanyDeletedConfirmationNotification(
            ownerName: $ownerName,
            companyName: $companyName,
        ));
    }
}
