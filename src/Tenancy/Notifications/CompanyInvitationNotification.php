<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Notifications;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\CompanyInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails an invited person the link to accept joining a company.
 *
 * Wording comes from the core's `larafoundry::tenancy` translations (same
 * pattern as the auth mails, decision D3-loc) so it ships localised and the host
 * can override text via published lang files. The accept URL carries the
 * invitation token; acceptance still verifies the email match server-side.
 */
class CompanyInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly CompanyInvitation $invitation,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $acceptUrl = route('tenancy.invitations.show', ['token' => $this->invitation->token]);
        $companyName = $this->invitation->company->name;

        return (new MailMessage)
            ->subject(__('larafoundry::tenancy.invitation.subject', ['company' => $companyName]))
            ->line(__('larafoundry::tenancy.invitation.intro', ['company' => $companyName]))
            ->action(__('larafoundry::tenancy.invitation.action'), $acceptUrl)
            ->line(__('larafoundry::tenancy.invitation.outro'));
    }
}
