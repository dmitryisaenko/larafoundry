<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Commission;

use Dmitryisaenko\LaraFoundry\Billing\Models\Affiliate;
use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;

/**
 * Computes the commission amount for a qualifying payment (Ф4.4) under the configured
 * basis — the "how much" axis, independent of the "which payments" trigger axis.
 *
 *  - percent  => a percentage of the NET charge, at the affiliate's effective rate
 *                (per-affiliate override, else the config default);
 *  - fixed    => a flat amount per qualifying payment, looked up per currency;
 *  - per_plan => an amount looked up per plan, per currency (the default basis).
 *
 * Every path is fail-closed: an unknown basis, an unmapped plan/currency, a non-positive
 * or non-integer config amount, or a zero rate all return null (no commission) — never a
 * throw, never a guessed amount. All money is the payment's own currency; there is no
 * cross-currency conversion.
 */
final class CommissionCalculator
{
    public function calculate(Affiliate $affiliate, CompanyPayment $payment): ?CommissionResult
    {
        $basis = (string) config('larafoundry-billing.affiliate.commission.basis', 'per_plan');
        $currency = strtoupper((string) $payment->currency);

        return match ($basis) {
            'percent' => $this->percent($affiliate, $payment),
            'fixed' => $this->fixed($currency),
            'per_plan' => $this->perPlan((string) $payment->plan_id, $currency),
            default => null, // unknown basis => no commission
        };
    }

    private function percent(Affiliate $affiliate, CompanyPayment $payment): ?CommissionResult
    {
        $rateBp = $affiliate->effectiveRateBp();

        if ($rateBp <= 0) {
            return null;
        }

        // Net charge in minor units (never negative); floor to whole minor units.
        $amount = intdiv($payment->getTotalAmount() * $rateBp, 10000);

        return $amount > 0 ? new CommissionResult($amount, 'percent', $rateBp) : null;
    }

    private function fixed(string $currency): ?CommissionResult
    {
        $amount = $this->lookup((array) config('larafoundry-billing.affiliate.commission.fixed', []), $currency);

        return $amount !== null ? new CommissionResult($amount, 'fixed') : null;
    }

    private function perPlan(string $planId, string $currency): ?CommissionResult
    {
        $perPlan = (array) config('larafoundry-billing.affiliate.commission.per_plan', []);
        $planMap = $perPlan[$planId] ?? null;

        if (! is_array($planMap)) {
            return null;
        }

        $amount = $this->lookup($planMap, $currency);

        return $amount !== null ? new CommissionResult($amount, 'per_plan') : null;
    }

    /**
     * A positive integer minor-units amount for the currency, or null. Non-int /
     * non-positive config values are rejected, not coerced (a price silently truncated
     * from a float is a billing bug).
     *
     * @param  array<string, mixed>  $map
     */
    private function lookup(array $map, string $currency): ?int
    {
        $amount = $map[$currency] ?? null;

        return is_int($amount) && $amount > 0 ? $amount : null;
    }
}
