<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Resources;

use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanRepositoryContract;
use Dmitryisaenko\LaraFoundry\Billing\Http\Resources\CompanyPaymentResource;
use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialises one payment for the super-admin payments console (sub-phase B7b), ported
 * from the donor's `App\Http\Resources\Admin\AdminPaymentResource`.
 *
 * Differs from the owner-facing {@see CompanyPaymentResource}
 * in two ways, both deliberate:
 *  - it INCLUDES `payment_response` — already reduced to an allowlisted, PII-free safe
 *    subset on write ({@see CompanyPayment::SAFE_RESPONSE_KEYS}) — for support/dispute
 *    triage. This resource is rendered ONLY by the super-admin-gated PaymentController.
 *  - `plan_name` resolves through the {@see PlanRepositoryContract} (the add-on's plan
 *    source), not the donor's hardcoded `config('own.company_plans.*')`.
 *
 * Money stays in minor units (cents); the Vue layer formats it. There is no currency
 * conversion (that is B9) — totals are reported per currency by the controller.
 *
 * @mixin CompanyPayment
 */
class AdminPaymentResource extends JsonResource
{
    /**
     * Ids of the most-recent successful payment per company, set by the controller for
     * the current page so the table can flag a company's latest charge.
     *
     * @var list<int>
     */
    public static array $latestPaymentIds = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CompanyPayment $payment */
        $payment = $this->resource;

        $paidAt = $payment->paid_at ?? $payment->created_at;

        return [
            'id' => $payment->getKey(),
            'paid_at' => $paidAt?->format('Y-m-d H:i:s'),
            'paid_at_date' => $paidAt?->format('d.m.Y'),
            'paid_at_time' => $paidAt?->format('H:i'),
            'user_id' => $payment->user_id,
            'user_email' => $payment->user?->email,
            'user_fullname' => $this->fullname($payment),
            'user_country' => $payment->user?->country,
            'company_id' => $payment->company_id,
            'company_name' => $payment->company?->name,
            'company_country' => $payment->company?->country,
            'plan_id' => $payment->plan_id,
            'plan_name' => $this->planName($payment->plan_id),
            'billing_period' => $payment->billing_period,
            'period_end' => $payment->period_end?->format('Y-m-d'),
            'is_latest_for_company' => in_array($payment->getKey(), self::$latestPaymentIds, true),
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'promo_code' => $payment->promoCode?->code,
            'discount_amount' => $payment->discount_amount,
            'discount_reason' => $payment->discount_reason,
            'total_amount' => $payment->getTotalAmount(),
            'payment_status' => $payment->payment_status,
            'payment_method' => $payment->payment_method,
            // Already allowlisted on write (SAFE_RESPONSE_KEYS) — super-admin-only surface.
            'payment_response' => $payment->payment_response,
        ];
    }

    /**
     * The plan's display name (English-as-key, rendered by Vue $t()), or the raw id
     * when the plan was retired / billing ships no plans.
     */
    private function planName(?string $planId): ?string
    {
        if ($planId === null || $planId === '') {
            return null;
        }

        return app(PlanRepositoryContract::class)->find($planId)?->name() ?? $planId;
    }

    /**
     * Compose the payer's full name from the host user's name/lastname (the core user
     * has no getFullname()); empty when the payment carries no user.
     */
    private function fullname(CompanyPayment $payment): string
    {
        $user = $payment->user;

        if (! is_object($user)) {
            return '';
        }

        return trim((string) ($user->name ?? '').' '.(string) ($user->lastname ?? ''));
    }
}
