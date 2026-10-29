<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Emails a company owner that a payment succeeded (sub-phase B7a).
 */
class PaymentSucceededNotification extends PaymentNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $locale = $this->localeFor($notifiable);

        return (new MailMessage)
            ->subject(__('larafoundry::billing.payment.success.subject', [], $locale))
            ->greeting(__('larafoundry::billing.payment.success.greeting', [], $locale))
            ->line(__('larafoundry::billing.payment.success.line', [
                'amount' => $this->formattedAmount(),
                'company' => $this->companyName(),
            ], $locale))
            ->line(__('larafoundry::billing.payment.success.thanks', [], $locale));
    }
}
