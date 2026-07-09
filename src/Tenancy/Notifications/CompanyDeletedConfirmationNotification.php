<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Notifications;

use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails an owner confirming their company was archived (matrix row 9). Archive,
 * not permanent delete: the copy says the data is kept and recoverable.
 *
 * Renders from the editable `company_deleted_confirmation` template with a static
 * `larafoundry::tenancy` fallback (decision D-5.1-8). Sent to the registered owner.
 */
class CompanyDeletedConfirmationNotification extends Notification
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
            'company_deleted_confirmation',
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
            ->subject(__('larafoundry::tenancy.notify_mail.company_deleted.subject', ['company' => $this->companyName]))
            ->line(__('larafoundry::tenancy.notify_mail.company_deleted.line', ['company' => $this->companyName]));
    }
}
