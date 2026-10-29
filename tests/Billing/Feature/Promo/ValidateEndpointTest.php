<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The public validate endpoint: a valid code returns 200 with its kind, an invalid
 * one a single generic 422 (anti-oracle), and exceeding the rate limit a 429 (the
 * donor's equivalent had no throttle).
 */
function makeDiscountCode(array $attributes = []): PromoCode
{
    return PromoCode::query()->create(array_merge([
        'code' => 'SAVE20',
        'kind' => PromoCode::KIND_DISCOUNT,
        'discount_type' => 'percentage',
        'discount_value' => 20,
        'single_use_per_user' => true,
        'is_active' => true,
    ], $attributes));
}

it('confirms a valid code with its kind and an estimate preview', function () {
    makeDiscountCode();

    $this->postJson('/larafoundry-billing/promo/validate', ['code' => 'SAVE20', 'amount' => 5000])
        ->assertOk()
        ->assertJson([
            'valid' => true,
            'kind' => PromoCode::KIND_DISCOUNT,
            'preview_discount' => 1000, // 20% of 5000, an estimate
            'preview_is_estimate' => true,
        ]);
});

it('returns a single generic 422 for an unknown code (no oracle)', function () {
    $this->postJson('/larafoundry-billing/promo/validate', ['code' => 'NOPE'])
        ->assertStatus(422)
        ->assertJson(['valid' => false])
        ->assertJsonMissing(['kind' => PromoCode::KIND_DISCOUNT]);
});

it('returns the same generic 422 for an expired code as for an unknown one', function () {
    makeDiscountCode(['code' => 'OLD', 'expires_at' => now()->subDay()]);

    $unknown = $this->postJson('/larafoundry-billing/promo/validate', ['code' => 'GHOST'])->json();
    $expired = $this->postJson('/larafoundry-billing/promo/validate', ['code' => 'OLD'])->json();

    // Identical shape: the caller cannot tell "expired" from "never existed".
    expect($expired)->toEqual($unknown);
});

it('throttles repeated validate attempts', function () {
    // The limiter allows 5/min; the 6th attempt is rejected with 429.
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/larafoundry-billing/promo/validate', ['code' => 'SPRAY'])
            ->assertStatus(422);
    }

    $this->postJson('/larafoundry-billing/promo/validate', ['code' => 'SPRAY'])
        ->assertStatus(429);
});
