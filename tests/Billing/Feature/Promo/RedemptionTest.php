<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Actions\RedeemPromoCodeAction;
use Dmitryisaenko\LaraFoundry\Billing\Events\PromoCodeRedeemed;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\PromoCodeUnavailableException;
use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCode;
use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCodeRedemption;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The paid redemption path (decision D-B5-1 hybrid): the action maps a registry
 * code to the gateway's coupon id, records the ledger row and bumps usage — but
 * NEVER computes a charged amount. The unique (promo_code_id, user_id) index is the
 * DB backstop against a concurrent double-redeem.
 */
function redemptionCompany(string $slug = 'redeem'): BillableCompany
{
    $userId = DB::table('users')->insertGetId([
        'name' => 'Owner',
        'email' => "owner+{$slug}@example.test",
        'password' => bcrypt('secret'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $company = new BillableCompany;
    $company->forceFill([
        'uuid' => (string) Str::uuid(),
        'name' => 'Acme',
        'slug' => $slug,
        'country' => 'US',
        'created_by_id' => $userId,
    ])->save();

    return $company->refresh();
}

function discountCode(array $attributes = []): PromoCode
{
    return PromoCode::query()->create(array_merge([
        'code' => 'SAVE',
        'kind' => PromoCode::KIND_DISCOUNT,
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'single_use_per_user' => true,
        'is_active' => true,
        'stripe_coupon_id' => 'promo_stripe_save',
    ], $attributes));
}

it('returns the gateway coupon id, writes a ledger row and increments usage', function () {
    Event::fake([PromoCodeRedeemed::class]);
    $company = redemptionCompany('rd-ok');
    $code = discountCode();

    $couponId = app(RedeemPromoCodeAction::class)->execute($code, $company, 'stripe', userId: 11);

    expect($couponId)->toBe('promo_stripe_save')
        ->and($code->refresh()->used_count)->toBe(1);

    $row = PromoCodeRedemption::query()->where('promo_code_id', $code->getKey())->first();
    expect($row->gateway)->toBe('stripe')
        ->and($row->gateway_reference)->toBe('promo_stripe_save')
        ->and($row->user_id)->toBe(11)
        ->and($row->kind)->toBe(PromoCode::KIND_DISCOUNT);

    Event::assertDispatched(PromoCodeRedeemed::class);
});

it('refuses a code with no coupon id for the active gateway', function () {
    $company = redemptionCompany('rd-nogw');
    // Only a Stripe coupon mapped; redeeming via Paddle must fail loudly.
    $code = discountCode(['code' => 'STRIPEONLY']);

    expect(fn () => app(RedeemPromoCodeAction::class)->execute($code, $company, 'paddle', userId: 1))
        ->toThrow(PromoCodeUnavailableException::class);
});

it('refuses to redeem a free-month code through the paid path', function () {
    $company = redemptionCompany('rd-fm');
    $code = discountCode(['code' => 'FREE', 'kind' => PromoCode::KIND_FREE_MONTH]);

    expect(fn () => app(RedeemPromoCodeAction::class)->execute($code, $company, 'stripe', userId: 1))
        ->toThrow(PromoCodeUnavailableException::class);
});

it('refuses to redeem a single-use code without an authenticated user', function () {
    $company = redemptionCompany('rd-anon');
    // Default single_use_per_user is true; a null user cannot be de-duplicated by
    // the ledger, so the redeem path must refuse rather than record it.
    $code = discountCode(['code' => 'ANON']);

    expect(fn () => app(RedeemPromoCodeAction::class)->execute($code, $company, 'stripe', userId: null))
        ->toThrow(PromoCodeUnavailableException::class);

    expect($code->refresh()->used_count)->toBe(0);
});

it('rejects a duplicate redemption via the unique ledger index (race backstop)', function () {
    $company = redemptionCompany('rd-dup');
    $code = discountCode(['code' => 'DUP', 'single_use_per_user' => false]);

    // First redemption succeeds.
    app(RedeemPromoCodeAction::class)->execute($code, $company, 'stripe', userId: 99);

    // A raw second insert for the same (promo_code_id, user_id) hits the unique
    // index — the DB-level guarantee behind the application's single-use guard.
    expect(fn () => PromoCodeRedemption::query()->create([
        'promo_code_id' => $code->getKey(),
        'company_id' => $company->getTenantKey(),
        'user_id' => 99,
        'kind' => PromoCode::KIND_DISCOUNT,
        'redeemed_at' => Carbon::now(),
    ]))->toThrow(QueryException::class);
});
