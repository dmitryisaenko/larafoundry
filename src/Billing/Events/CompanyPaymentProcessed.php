<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Events;

use Dmitryisaenko\LaraFoundry\Billing\Listeners\NotifyOwnersOfPayment;
use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after the add-on records a payment from a verified gateway webhook
 * (sub-phase B7a) — success or failure (read `$payment->payment_status`).
 *
 * The add-on's analogue of the donor's `CompanyPaymentProcessed`, trimmed to carry
 * just the ledger row (it already links the company and the user). A host listens to
 * it for its own in-app notifications, analytics or accounting; the add-on's own
 * {@see NotifyOwnersOfPayment} listens to
 * email the owners. {@see getLogProperties()} feeds the core's activity log without
 * leaking PII (ids, money and status only).
 */
class CompanyPaymentProcessed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly CompanyPayment $payment) {}

    /**
     * @return array<string, mixed>
     */
    public function getLogProperties(): array
    {
        return [
            'company_id' => $this->payment->company_id,
            'payment_id' => $this->payment->getKey(),
            'plan_id' => $this->payment->plan_id,
            'amount' => $this->payment->amount,
            'currency' => $this->payment->currency,
            'payment_status' => $this->payment->payment_status,
            'payment_method' => $this->payment->payment_method,
        ];
    }
}
