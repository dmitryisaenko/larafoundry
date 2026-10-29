<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Listeners;

use Dmitryisaenko\LaraFoundry\Billing\Events\CompanyPaymentProcessed;
use Dmitryisaenko\LaraFoundry\Billing\Gateways\CashierStripeDriver;
use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Billing\Support\BillingSubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Records a ledger row from a verified Stripe invoice webhook (sub-phase B7a) and
 * fires {@see CompanyPaymentProcessed}.
 *
 * Cashier verified the signature and parsed the payload before firing
 * {@see WebhookReceived}, so this runs on trusted data. It is the SECOND listener on
 * that event beside {@see MirrorStripeSubscriptionState}: they never overlap — the
 * mirror moves access dates on `customer.subscription.*`, this records money on
 * `invoice.*`. The provider only registers it with the Stripe driver configured AND a
 * signing secret present (fail-closed, same as the mirror).
 *
 * Writes go THROUGH the model so {@see CompanyPayment}'s allowlist redaction applies to
 * the stored payload. Idempotent on (gateway_reference, status): a re-delivered webhook
 * updates the same row, and the table's unique index is the race backstop.
 */
final class RecordStripePayment
{
    public function __construct(private readonly CashierStripeDriver $driver) {}

    public function handle(WebhookReceived $event): void
    {
        $type = $event->payload['type'] ?? null;

        if (! is_string($type)) {
            return;
        }

        $status = match ($type) {
            'invoice.paid', 'invoice.payment_succeeded' => CompanyPayment::STATUS_SUCCESS,
            'invoice.payment_failed' => CompanyPayment::STATUS_FAILED,
            default => null,
        };

        if ($status === null) {
            return;
        }

        $invoice = $event->payload['data']['object'] ?? null;

        if (! is_array($invoice)) {
            return;
        }

        $customerId = $invoice['customer'] ?? null;

        if (! is_string($customerId) || $customerId === '') {
            return;
        }

        $billable = Cashier::findBillable($customerId);

        if (! $billable instanceof Model) {
            return;
        }

        $payment = $this->record($billable, $invoice, $type, $status);

        CompanyPaymentProcessed::dispatch($payment);
    }

    private function record(Model $billable, array $invoice, string $type, string $status): CompanyPayment
    {
        $line = $invoice['lines']['data'][0] ?? [];
        $line = is_array($line) ? $line : [];

        $priceId = $line['price']['id'] ?? ($line['plan']['id'] ?? null);
        $planId = is_string($priceId) && $priceId !== ''
            ? ($this->driver->planIdForPrice($priceId) ?? $priceId)
            : 'unknown';

        $interval = $line['price']['recurring']['interval'] ?? ($line['plan']['interval'] ?? null);
        $period = $interval === 'year' ? CompanyPayment::PERIOD_YEARLY : CompanyPayment::PERIOD_MONTHLY;

        $amountRaw = $status === CompanyPayment::STATUS_SUCCESS
            ? ($invoice['amount_paid'] ?? $invoice['amount_due'] ?? 0)
            : ($invoice['amount_due'] ?? 0);
        $amount = is_int($amountRaw) ? max(0, $amountRaw) : 0;

        $currency = strtoupper((string) ($invoice['currency'] ?? 'usd'));
        $invoiceId = is_string($invoice['id'] ?? null) ? $invoice['id'] : null;

        // Who the ledger row is billed TO and who paid it. In `teams` mode the billable
        // is a company and the payer of record is its founder (`created_by_id`); in
        // `personal` mode the billable IS the person, so both columns carry their id.
        $userId = BillingSubject::isPersonal()
            ? $billable->getKey()
            : $billable->getAttribute('created_by_id');

        $attributes = [
            'company_id' => BillingSubject::key($billable),
            'user_id' => is_int($userId) ? $userId : null,
            'plan_id' => $planId,
            'billing_period' => $period,
            'amount' => $amount,
            'currency' => substr($currency, 0, 3),
            'payment_method' => 'stripe',
            'payment_status' => $status,
            'gateway_reference' => $invoiceId,
            'period_start' => $this->toDate($line['period']['start'] ?? null),
            'period_end' => $this->toDate($line['period']['end'] ?? null),
            'payment_response' => [
                'gateway' => 'stripe',
                'event' => $type,
                'id' => $invoiceId,
                'status' => $invoice['status'] ?? null,
                'amount' => $amount,
                'currency' => $currency,
                'subscription' => $invoice['subscription'] ?? null,
                'payment_intent' => $invoice['payment_intent'] ?? null,
            ],
        ];

        if ($status === CompanyPayment::STATUS_SUCCESS) {
            $attributes['paid_at'] = Carbon::now();
        }

        if ($invoiceId !== null) {
            return CompanyPayment::updateOrCreate(
                ['gateway_reference' => $invoiceId, 'payment_status' => $status],
                $attributes,
            );
        }

        return CompanyPayment::create($attributes);
    }

    private function toDate(mixed $timestamp): ?Carbon
    {
        return is_int($timestamp) ? Carbon::createFromTimestamp($timestamp) : null;
    }
}
