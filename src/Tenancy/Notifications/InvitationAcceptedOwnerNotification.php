<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Notifications;

use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a company owner that an invited person accepted and joined (matrix rows
 * 1.1 / 2.1).
 *
 * Body renders from the editable `invitation_accepted_owner` template, falling
 * back to a static MailMessage built from the core's `larafoundry::tenancy`
 * strings when that template is switched off (graceful, decision D-5.1-8). Sent to
 * a registered user (the owner), so the notifiable owns addressing + locale.
 */
class InvitationAcceptedOwnerNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $memberName,
        public readonly string $companyName,
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
        $appName = (string) config('app.name', 'LaraFoundry');

        $templated = app(EmailTemplateRepository::class)->mailMessage(
            'invitation_accepted_owner',
            $notifiable->locale ?? null,
            [
                'app_name' => $appName,
                'owner_name' => $this->ownerName,
                'member_name' => $this->memberName,
                'company_name' => $this->companyName,
            ],
        );

        if ($templated !== null) {
            return $templated;
        }

        return (new MailMessage)
            ->subject(__('larafoundry::tenancy.notify_mail.invitation_accepted_owner.subject', ['company' => $this->companyName]))
            ->line(__('larafoundry::tenancy.notify_mail.invitation_accepted_owner.line', ['member' => $this->memberName, 'company' => $this->companyName]));
    }
}
