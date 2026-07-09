<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Notifications;

use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a member that their own removal request was approved and they have been
 * removed (matrix row 4.1 only; a plain owner-initiated removal, row 3, sends no
 * email).
 *
 * Renders from the editable `employee_removed_notification` template with a static
 * `larafoundry::tenancy` fallback (decision D-5.1-8). Sent to the removed member.
 */
class EmployeeRemovedNotification extends Notification
{
    use Queueable;

    public function __construct(
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
            'employee_removed_notification',
            $notifiable->locale ?? null,
            [
                'app_name' => $appName,
                'member_name' => $this->memberName,
                'company_name' => $this->companyName,
            ],
        );

        if ($templated !== null) {
            return $templated;
        }

        return (new MailMessage)
            ->subject(__('larafoundry::tenancy.notify_mail.employee_removed.subject', ['company' => $this->companyName]))
            ->line(__('larafoundry::tenancy.notify_mail.employee_removed.line', ['company' => $this->companyName]));
    }
}
