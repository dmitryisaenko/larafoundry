<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Notifications;

use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\CompanyInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails an invited person the link to accept joining a company.
 *
 * Body renders from the editable `company_invitation` template (a super-admin can
 * rewrite the HTML), falling back to a MailMessage built from the core's
 * `larafoundry::tenancy` strings when that template is switched off (graceful,
 * decision D-5.1-8). The invitee has no account yet, so this is sent on-demand to
 * a raw address; the accept URL carries the invitation token and acceptance still
 * verifies the email match server-side.
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
        $companyName = (string) $this->invitation->company->name;

        $templated = app(EmailTemplateRepository::class)->mailMessage(
            'company_invitation',
            $notifiable->locale ?? null,
            [
                'company_name' => $companyName,
                'inviter_name' => (string) ($this->invitation->inviter?->name ?? ''),
                'accept_url' => $acceptUrl,
                'expires_at' => $this->invitation->expires_at?->toDayDateTimeString() ?? '',
            ],
        );

        if ($templated !== null) {
            return $templated;
        }

        return (new MailMessage)
            ->subject(__('larafoundry::tenancy.invitation.subject', ['company' => $companyName]))
            ->line(__('larafoundry::tenancy.invitation.intro', ['company' => $companyName]))
            ->action(__('larafoundry::tenancy.invitation.action'), $acceptUrl)
            ->line(__('larafoundry::tenancy.invitation.outro'));
    }
}
