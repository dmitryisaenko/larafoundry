<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Notifications;

use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a company owner that an invited person declined the invitation (matrix
 * row 2.2).
 *
 * Renders from the editable `invitation_rejected_owner` template with a static
 * `larafoundry::tenancy` fallback (decision D-5.1-8). Sent to the registered owner.
 */
class InvitationRejectedOwnerNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $invitedEmail,
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
            'invitation_rejected_owner',
            $notifiable->locale ?? null,
            [
                'app_name' => $appName,
                'owner_name' => $this->ownerName,
                'invited_email' => $this->invitedEmail,
                'company_name' => $this->companyName,
            ],
        );

        if ($templated !== null) {
            return $templated;
        }

        return (new MailMessage)
            ->subject(__('larafoundry::tenancy.notify_mail.invitation_rejected_owner.subject', ['company' => $this->companyName]))
            ->line(__('larafoundry::tenancy.notify_mail.invitation_rejected_owner.line', ['email' => $this->invitedEmail, 'company' => $this->companyName]));
    }
}
