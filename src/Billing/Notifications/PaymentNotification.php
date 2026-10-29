<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Notifications;

use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Shared base for the owner payment mails (sub-phase B7a).
 *
 * Queued so sending never blocks the webhook that recorded the payment. Self-contained
 * (no host `EmailTemplate` model — the donor's coupling): the subject/body are
 * `larafoundry::` lang strings the host overrides by publishing the lang files.
 * Money is formatted from minor units here (the model stores cents).
 *
 * Uses SerializesModels (sub-phase B7b cron-polish): the subject is the add-on's own
 * Eloquent CompanyPayment, so the queue payload stores just its class + key and the row
 * is re-fetched on handle — no whole-model bloat, and a figure corrected between enqueue
 * and processing (e.g. by a follow-up webhook) is sent fresh rather than from a stale
 * snapshot.
 */
abstract class PaymentNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    // Not readonly: SerializesModels re-hydrates the payment on unserialize via reflection
    // from the subclass scope, which a readonly property declared on this base would reject.
    public function __construct(public CompanyPayment $payment) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * The recipient's preferred locale, falling back to the app locale.
     */
    protected function localeFor(object $notifiable): string
    {
        $locale = $notifiable->locale ?? null;

        return is_string($locale) && $locale !== '' ? $locale : app()->getLocale();
    }

    /**
     * The net charge formatted for display, e.g. "25.00 USD". Two decimals assume a
     * minor-unit-of-100 currency (the common case); a host needing JPY-style currencies
     * overrides this notification.
     */
    protected function formattedAmount(): string
    {
        return sprintf('%.2f %s', $this->payment->getTotalAmount() / 100, strtoupper((string) $this->payment->currency));
    }

    protected function companyName(): string
    {
        $company = $this->payment->company;

        return is_object($company) && isset($company->name) ? (string) $company->name : '';
    }
}
