<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Commission;

use Dmitryisaenko\LaraFoundry\Billing\Models\AffiliateCommission;
use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Billing\Models\Referral;
use Illuminate\Support\Carbon;

/**
 * Decides whether a successful payment qualifies for a commission (Ф4.4) under the
 * configured trigger — the "which payments" axis, independent of the "how much" basis.
 *
 *  - first_payment => one commission per referral, on the first qualifying payment we see;
 *  - recurring      => every successful payment within the referral's window;
 *  - lifetime       => every successful payment, no date bound.
 *
 * An unknown trigger fails closed (qualifies nothing).
 */
final class TriggerEvaluator
{
    public function qualifies(Referral $referral, CompanyPayment $payment): bool
    {
        return match ((string) config('larafoundry-billing.affiliate.commission.trigger', 'recurring')) {
            // "First payment" means one bounty per referral, awarded to whichever success
            // we process first — NOT a chronological ordering of payment rows. Keying off
            // "has this referral earned yet" (rather than "is this the earliest row") makes
            // it correct under out-of-order / replayed webhooks, where two payments would
            // each look like the earliest at their own processing time and double-accrue.
            // A clawback voids the prior bounty, which re-opens the referral for the next
            // genuine payment.
            'first_payment' => ! $this->referralAlreadyCommissioned($referral),
            'lifetime' => true,
            'recurring' => $referral->isWithinWindow($this->paymentTime($payment)),
            default => false,
        };
    }

    /**
     * Whether the referral already carries a live (non-void) commission — the guard that
     * keeps the first_payment trigger to exactly one bounty per referral.
     */
    private function referralAlreadyCommissioned(Referral $referral): bool
    {
        return AffiliateCommission::query()
            ->where('referral_id', $referral->getKey())
            ->whereIn('status', [
                AffiliateCommission::STATUS_PENDING,
                AffiliateCommission::STATUS_APPROVED,
                AffiliateCommission::STATUS_PAID,
            ])
            ->exists();
    }

    private function paymentTime(CompanyPayment $payment): Carbon
    {
        return $payment->paid_at ?? $payment->created_at ?? Carbon::now();
    }
}
