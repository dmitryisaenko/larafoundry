<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerts an admin that someone attempted (and failed) to log into the admin
 * account.
 *
 * Mail-only and localised through `larafoundry::auth` so the host can override
 * the wording. The donor also routed this to Telegram with a hard-coded bot
 * token; that is intentionally dropped — host-specific channels belong in the
 * host's notification config, not the core.
 */
class AdminLoginAttemptNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $step,
        public readonly string $ip,
        public readonly string $userAgent,
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
        return (new MailMessage)
            ->subject(__('larafoundry::auth.admin_alert.subject'))
            ->line(__('larafoundry::auth.admin_alert.intro', ['step' => $this->step]))
            ->line(__('larafoundry::auth.admin_alert.ip', ['ip' => $this->ip]))
            ->line(__('larafoundry::auth.admin_alert.agent', ['agent' => $this->userAgent]))
            ->line(__('larafoundry::auth.admin_alert.outro'));
    }
}
