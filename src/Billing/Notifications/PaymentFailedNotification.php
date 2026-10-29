<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Emails a company owner that a payment failed (sub-phase B7a).
 */
class PaymentFailedNotification extends PaymentNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $locale = $this->localeFor($notifiable);

        return (new MailMessage)
            ->subject(__('larafoundry::billing.payment.failed.subject', [], $locale))
            ->greeting(__('larafoundry::billing.payment.failed.greeting', [], $locale))
            ->line(__('larafoundry::billing.payment.failed.line', [
                'amount' => $this->formattedAmount(),
                'company' => $this->companyName(),
            ], $locale))
            ->line(__('larafoundry::billing.payment.failed.help', [], $locale));
    }
}
