<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Http\Resources;

use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Owner-facing view of a payment row for the history page (sub-phase B7a).
 *
 * Deliberately omits `payment_response` (the gateway payload) — that is an internal
 * audit field that may carry sensitive data and never goes to the browser. Money stays
 * in minor units (cents); the Vue layer formats it.
 *
 * @mixin CompanyPayment
 */
final class CompanyPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CompanyPayment $payment */
        $payment = $this->resource;

        return [
            'id' => $payment->getKey(),
            'plan_id' => $payment->plan_id,
            'billing_period' => $payment->billing_period,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'discount_amount' => $payment->discount_amount,
            'total' => $payment->getTotalAmount(),
            'payment_status' => $payment->payment_status,
            'payment_method' => $payment->payment_method,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'period_start' => $payment->period_start?->toDateString(),
            'period_end' => $payment->period_end?->toDateString(),
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }
}
