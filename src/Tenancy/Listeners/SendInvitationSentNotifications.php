<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Listeners;

use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyInvitationSent;
use Dmitryisaenko\LaraFoundry\Tenancy\Listeners\Concerns\NotifiesLifecycle;
use Illuminate\Database\Eloquent\Model;

/**
 * Matrix rows 1 / 2 (invite): in-app to the owner on every invite, and in-app to
 * the invitee ONLY when they already have an account (row 2, registered invitee).
 *
 * No email here: the invitee's email is the existing `company_invitation` template
 * queued by the invite action (rows 1 + 6), and the owner gets no invite email per
 * the matrix.
 */
class SendInvitationSentNotifications
{
    use NotifiesLifecycle;

    public function handle(CompanyInvitationSent $event): void
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
        $companyName = (string) $company->name;

        // Owner in-app (fires once per invite, regardless of registered/unregistered).
        if ($owner !== null) {
            $locale = $this->localeFor($owner);
            $this->notifications->system(
                users: [$owner],
                code: 'info',
                titleKey: 'larafoundry::notifications.tenancy.invited.owner.title',
                bodyKey: 'larafoundry::notifications.tenancy.invited.owner.body',
                params: ['email' => $invitation->email, 'company' => $companyName],
                data: $this->action('larafoundry::notifications.tenancy.action_view_team', '/employees', $locale),
            );
        }

        // Registered invitee in-app (row 2 only): look up an account for the email.
        $invitee = $this->userByEmail($invitation->email);

        if ($invitee !== null) {
            $locale = $this->localeFor($invitee);
            $inviterName = $this->displayName($invitation->inviter);
            $this->notifications->system(
                users: [$invitee],
                code: 'info',
                titleKey: 'larafoundry::notifications.tenancy.invited.user.title',
                bodyKey: 'larafoundry::notifications.tenancy.invited.user.body',
                params: ['company' => $companyName, 'inviter' => $inviterName],
                data: $this->action('larafoundry::notifications.tenancy.action_view_home', '/', $locale),
            );
        }
    }

    /**
     * Resolve a user account for an invited email, or null when unregistered.
     */
    protected function userByEmail(string $email): ?object
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        return $model::query()->where('email', $email)->first();
    }
}
