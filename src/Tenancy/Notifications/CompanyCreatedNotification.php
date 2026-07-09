<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Notifications;

use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails an owner that their new company is ready (matrix row 8).
 *
 * Renders from the editable `company_created` template with a static
 * `larafoundry::tenancy` fallback (decision D-5.1-8). Sent to the registered owner.
 */
class CompanyCreatedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $ownerName,
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
            'company_created',
            $notifiable->locale ?? null,
            [
                'app_name' => $appName,
                'owner_name' => $this->ownerName,
                'company_name' => $this->companyName,
            ],
        );

        if ($templated !== null) {
            return $templated;
        }

        return (new MailMessage)
            ->subject(__('larafoundry::tenancy.notify_mail.company_created.subject', ['company' => $this->companyName]))
            ->line(__('larafoundry::tenancy.notify_mail.company_created.line', ['company' => $this->companyName]));
    }
}
