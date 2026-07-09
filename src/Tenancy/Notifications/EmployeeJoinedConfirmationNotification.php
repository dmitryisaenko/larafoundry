<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Notifications;

use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a member confirming they joined a company after accepting an invitation
 * (matrix rows 1.1 / 2.1, UserEmail).
 *
 * Renders from the editable `employee_joined_confirmation` template with a static
 * `larafoundry::tenancy` fallback (decision D-5.1-8). Sent to the joining member.
 */
class EmployeeJoinedConfirmationNotification extends Notification
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
            'employee_joined_confirmation',
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
            ->subject(__('larafoundry::tenancy.notify_mail.employee_joined.subject', ['company' => $this->companyName]))
            ->line(__('larafoundry::tenancy.notify_mail.employee_joined.line', ['company' => $this->companyName]));
    }
}
