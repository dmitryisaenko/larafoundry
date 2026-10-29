<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Listeners;

use Dmitryisaenko\LaraFoundry\Billing\Commission\CommissionCalculator;
use Dmitryisaenko\LaraFoundry\Billing\Commission\TriggerEvaluator;
use Dmitryisaenko\LaraFoundry\Billing\Events\CompanyPaymentProcessed;
use Dmitryisaenko\LaraFoundry\Billing\Models\AffiliateCommission;
use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Billing\Models\Referral;

/**
 * Accrues (or claws back) an affiliate commission from a recorded payment (Ф4.4).
 *
 * Listens to the add-on's own {@see CompanyPaymentProcessed}, which fires for both
 * success and failure after a verified webhook. On a SUCCESS of a referred company's
 * payment it creates one pending commission — gated by the configured trigger (which
 * payments) and basis (how much). On a FAILURE that reverses a previously-succeeded
 * invoice it voids that invoice's commission (clawback), unless it was already paid out.
 *
 * Idempotent: the commission is keyed firstOrCreate on `company_payment_id` (which the
 * table also makes unique), so a re-delivered webhook never double-credits.
 *
 * Pure refunds (a separate Stripe event the recorder does not log as a failed payment)
 * are handled by the super-admin's manual void in the payout console — this listener
 * covers the same-invoice failure path automatically.
 */
final class AccrueAffiliateCommission
{
    public function __construct(
        private readonly TriggerEvaluator $trigger,
        private readonly CommissionCalculator $calculator,
    ) {}

    public function handle(CompanyPaymentProcessed $event): void
    {
        if (! config('larafoundry-billing.affiliate.enabled')) {
            return;
        }

        $payment = $event->payment;

        if ($payment->payment_status === CompanyPayment::STATUS_FAILED) {
            $this->clawback($payment);

            return;
        }

        if ($payment->payment_status !== CompanyPayment::STATUS_SUCCESS) {
            return;
        }

        $referral = Referral::query()->where('referred_company_id', $payment->company_id)->first();

        if ($referral === null) {
            return; // company was not referred
        }

        $affiliate = $referral->affiliate;

        if ($affiliate === null || ! $affiliate->isActive()) {
            return;
        }

        if (! $this->trigger->qualifies($referral, $payment)) {
            return;
        }

        $result = $this->calculator->calculate($affiliate, $payment);

        if ($result === null || $result->amount <= 0) {
            return;
        }

        AffiliateCommission::query()->firstOrCreate(
            ['company_payment_id' => $payment->getKey()],
            [
                'affiliate_id' => $affiliate->getKey(),
                'referral_id' => $referral->getKey(),
                'company_id' => $payment->company_id,
                // Normalise to upper-case so per-currency totals never split "usd"/"USD".
                'currency' => strtoupper((string) $payment->currency),
                'amount' => $result->amount,
                'status' => AffiliateCommission::STATUS_PENDING,
                'basis' => $result->basis,
                'rate_bp' => $result->rateBp,
            ],
        );
    }

    /**
     * Void the commission of a previously-succeeded invoice when the same invoice later
     * fails. A commission already marked paid is left alone (the money is out — that is a
     * manual reconciliation in the console, not an automatic reversal).
     */
    private function clawback(CompanyPayment $failed): void
    {
        $reference = $failed->gateway_reference;

        if (! is_string($reference) || $reference === '') {
            return;
        }

        $successId = CompanyPayment::query()
            ->where('company_id', $failed->company_id)
            ->where('gateway_reference', $reference)
            ->where('payment_status', CompanyPayment::STATUS_SUCCESS)
            ->value('id');

        if ($successId === null) {
            return;
        }

        $commission = AffiliateCommission::query()->where('company_payment_id', $successId)->first();

        if ($commission === null) {
            return;
        }

        if (in_array($commission->status, [AffiliateCommission::STATUS_PENDING, AffiliateCommission::STATUS_APPROVED], true)) {
            $commission->void();
        }
    }
}
