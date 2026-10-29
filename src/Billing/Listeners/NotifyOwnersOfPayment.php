<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Listeners;

use Dmitryisaenko\LaraFoundry\Billing\Events\CompanyPaymentProcessed;
use Dmitryisaenko\LaraFoundry\Billing\Notifications\PaymentFailedNotification;
use Dmitryisaenko\LaraFoundry\Billing\Notifications\PaymentSucceededNotification;
use Dmitryisaenko\LaraFoundry\Billing\Support\BillingSubject;
use Illuminate\Support\Collection;

/**
 * Emails a company's owner when a payment is processed (sub-phase B7a).
 *
 * Listens to {@see CompanyPaymentProcessed} and sends the success or failure mail
 * (queued, via the notification) to the company's owner of record — its founder
 * (`created_by_id`). Inert when `larafoundry-billing.notifications.enabled` is false.
 *
 * It resolves the founder rather than the `owners()` pivot deliberately: `createdBy`
 * keys off an explicit column and is robust regardless of the host's model names,
 * whereas the pivot relation infers its keys from the Company/User class names. A host
 * that wants to fan out to every co-owner listens to {@see CompanyPaymentProcessed}
 * itself — the add-on guarantees the owner of record is reached out of the box.
 *
 * In `personal` mode there is no founder to resolve: the payer is the user, and the
 * receipt goes to them directly.
 */
final class NotifyOwnersOfPayment
{
    public function handle(CompanyPaymentProcessed $event): void
    {
        if (! config('larafoundry-billing.notifications.enabled', true)) {
            return;
        }

        $company = $event->payment->company;

        if (! is_object($company)) {
            return;
        }

        $recipients = $this->recipients($company);

        if ($recipients->isEmpty()) {
            return;
        }

        $notification = $event->payment->isSuccessful()
            ? new PaymentSucceededNotification($event->payment)
            : new PaymentFailedNotification($event->payment);

        foreach ($recipients as $recipient) {
            if (method_exists($recipient, 'notify')) {
                $recipient->notify($notification);
            }
        }
    }

    /**
     * Who to notify: the company's founder (owner of record) in `teams` mode.
     *
     * In `personal` mode the billable IS the person who paid, so the subject itself is
     * the recipient — there is no founder column to follow, and without this branch the
     * receipt would simply never be sent.
     *
     * @return Collection<int, object>
     */
    private function recipients(object $company): Collection
    {
        if (BillingSubject::isPersonal()) {
            return collect([$company]);
        }

        $creator = method_exists($company, 'createdBy') ? $company->createdBy()->first() : null;

        return $creator !== null ? collect([$creator]) : collect();
    }
}
