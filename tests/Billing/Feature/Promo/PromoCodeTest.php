<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCode;
use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCodeRedemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * The promo-code registry's own rules: validity, usability (including the
 * redemption-ledger single-use check that replaces the donor's CompanyPayment
 * query), status, and the preview-only discount math. No gateway involved.
 */
function makePromoCode(array $attributes = []): PromoCode
{
    return PromoCode::query()->create(array_merge([
        'code' => 'SAVE10',
        'kind' => PromoCode::KIND_DISCOUNT,
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'single_use_per_user' => true,
        'is_active' => true,
    ], $attributes));
}

it('is invalid when inactive', function () {
    expect(makePromoCode(['is_active' => false])->isValid())->toBeFalse();
});

it('is invalid when expired', function () {
    $code = makePromoCode(['expires_at' => Carbon::now()->subDay()]);

    expect($code->isValid())->toBeFalse()
        ->and($code->getStatus())->toBe('expired');
});

it('cannot be used past its max uses', function () {
    $code = makePromoCode(['max_uses' => 2, 'used_count' => 2]);

    expect($code->canBeUsed(userId: 1))->toBeFalse()
        ->and($code->getStatus())->toBe('exhausted');
});

it('refuses a personal code for a different user', function () {
    $code = makePromoCode(['code' => 'VIP', 'user_id' => 7]);

    expect($code->canBeUsed(userId: 9))->toBeFalse()
        ->and($code->canBeUsed(userId: 7))->toBeTrue()
        ->and($code->isPersonal())->toBeTrue();
});

it('refuses a single-use code a user already redeemed', function () {
    $code = makePromoCode(['code' => 'ONCE']);

    // Fresh code: usable.
    expect($code->canBeUsed(userId: 5))->toBeTrue();

    PromoCodeRedemption::query()->create([
        'promo_code_id' => $code->getKey(),
        'company_id' => 1,
        'user_id' => 5,
        'kind' => PromoCode::KIND_DISCOUNT,
        'redeemed_at' => Carbon::now(),
    ]);

    // Same user cannot redeem again; a different user still can.
    expect($code->canBeUsed(userId: 5))->toBeFalse()
        ->and($code->canBeUsed(userId: 6))->toBeTrue();
});

it('stores discount_value as a whole integer, not a float', function () {
    $code = makePromoCode(['discount_value' => 25]);

    expect($code->fresh()->discount_value)->toBe(25)
        ->and($code->fresh()->discount_value)->toBeInt();
});

it('previews a percentage discount on a minor-units amount', function () {
    // 15% of 2000 cents = 300 cents. Preview only — never the charged amount.
    $code = makePromoCode(['discount_type' => 'percentage', 'discount_value' => 15]);

    expect($code->calculateDiscount(2000))->toBe(300);
});

it('caps a fixed discount at the amount', function () {
    $code = makePromoCode(['discount_type' => 'fixed', 'discount_value' => 5000]);

    expect($code->calculateDiscount(3000))->toBe(3000)
        ->and($code->calculateDiscount(8000))->toBe(5000);
});

it('maps a registry code to the gateway coupon id', function () {
    $code = makePromoCode([
        'stripe_coupon_id' => 'promo_stripe_x',
        'paddle_discount_id' => 'dsc_paddle_y',
    ]);

    expect($code->couponIdFor('stripe'))->toBe('promo_stripe_x')
        ->and($code->couponIdFor('paddle'))->toBe('dsc_paddle_y')
        ->and($code->couponIdFor('unknown'))->toBeNull();
});

it('recognises a free-month code', function () {
    $code = makePromoCode(['code' => 'FREEMONTH', 'kind' => PromoCode::KIND_FREE_MONTH]);

    expect($code->isFreeMonth())->toBeTrue();
});
