<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Actions;

use Dmitryisaenko\LaraFoundry\Billing\Events\PromoCodeRedeemed;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\PromoCodeUnavailableException;
use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCode;
use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCodeRedemption;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Record the redemption of a DISCOUNT promo code and hand its gateway id back to the
 * driver (sub-phase B5, decision D-B5-1 hybrid).
 *
 * This action owns the registry side of a discount: it re-validates under a row
 * lock, increments usage, and writes the audit/ledger row in ONE transaction so a
 * concurrent double-redeem cannot slip past (the `unique (promo_code_id, user_id)`
 * index is the DB backstop). It then returns the gateway coupon/discount id for the
 * driver to attach to the Checkout.
 *
 * It NEVER computes the charged amount. The gateway (Stripe Coupon / Paddle
 * Discount) applies and computes the discount; the final figure arrives by webhook.
 * That is the whole point of the hybrid: one source of truth about money, and it is
 * the gateway.
 *
 * Free-month codes are NOT redeemed here — they go through
 * {@see ActivateFreeMonthAction}, which is app-side and gateway-less.
 */
final class RedeemPromoCodeAction
{
    /**
     * @return string the gateway coupon/discount id to attach to the Checkout
     *
     * @throws PromoCodeUnavailableException when the code is unusable for this
     *                                       user/company or carries no id for the gateway
     */
    public function execute(
        PromoCode $code,
        Tenant $company,
        string $gateway,
        ?int $userId = null,
    ): string {
        return DB::transaction(function () use ($code, $company, $gateway, $userId): string {
            // Re-read under a lock so the validity check and the usage write are
            // atomic against a concurrent redemption of the same row.
            $locked = PromoCode::query()
                ->lockForUpdate()
                ->whereKey($code->getKey())
                ->first();

            if ($locked === null || $locked->isFreeMonth() || ! $locked->canBeUsed($userId, $company->getTenantKey())) {
                throw new PromoCodeUnavailableException('This promo code cannot be redeemed.');
            }

            // A single-use-per-user code REQUIRES an authenticated redeemer: the
            // per-user guard (canBeUsed's ledger check and the unique
            // (promo_code_id, user_id) index) both go silent on a NULL user_id —
            // SQL treats NULLs as distinct, so anonymous redemptions would not be
            // de-duplicated and the single-use invariant would not hold. Refuse
            // rather than record an unenforceable redemption.
            if ($locked->single_use_per_user && $userId === null) {
                throw new PromoCodeUnavailableException(
                    "Promo code [{$locked->code}] is single-use and requires an authenticated user."
                );
            }

            $couponId = $locked->couponIdFor($gateway);

            if ($couponId === null) {
                throw new PromoCodeUnavailableException(
                    "Promo code [{$locked->code}] has no {$gateway} discount id mapped."
                );
            }

            PromoCodeRedemption::create([
                'promo_code_id' => $locked->getKey(),
                'company_id' => $company->getTenantKey(),
                'user_id' => $userId,
                'kind' => PromoCode::KIND_DISCOUNT,
                'gateway' => $gateway,
                'gateway_reference' => $couponId,
                'redeemed_at' => Carbon::now(),
            ]);

            $locked->incrementUsage();

            PromoCodeRedeemed::dispatch($locked, $company, $userId);

            return $couponId;
        });
    }
}
