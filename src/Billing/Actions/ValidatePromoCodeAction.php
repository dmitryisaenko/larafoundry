<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Actions;

use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCode;

/**
 * Validate a promo code for a user/company (sub-phase B5).
 *
 * The single home for "may this code be redeemed", used by both the validate
 * endpoint (UI preview) and the redemption path. Fail-closed: an unknown or
 * unusable code returns {@see PromoCodeValidationResult::invalid()} with no reason,
 * so the caller cannot tell whether the code was missing, expired, exhausted, or
 * personal-mismatched — that uniformity is the anti-oracle guard.
 *
 * The user id is resolved server-side by the caller (the authenticated user),
 * never read from the request body, so a personal code cannot be probed against
 * another user's id.
 *
 * The optional preview amount feeds {@see PromoCode::calculateDiscount()} purely to
 * render a "you save X" estimate; it is not the charged amount.
 */
final class ValidatePromoCodeAction
{
    public function execute(
        string $code,
        ?int $userId = null,
        int|string|null $companyId = null,
        ?int $previewAmount = null,
    ): PromoCodeValidationResult {
        $promoCode = PromoCode::query()->where('code', $code)->first();

        if (! $promoCode instanceof PromoCode || ! $promoCode->canBeUsed($userId, $companyId)) {
            return PromoCodeValidationResult::invalid();
        }

        // A discount preview only when a base amount was supplied AND the code is a
        // discount code (a free-month code discounts nothing). Never the real charge.
        $preview = ($previewAmount !== null && ! $promoCode->isFreeMonth())
            ? $promoCode->calculateDiscount($previewAmount)
            : null;

        return PromoCodeValidationResult::valid($promoCode, $preview);
    }
}
