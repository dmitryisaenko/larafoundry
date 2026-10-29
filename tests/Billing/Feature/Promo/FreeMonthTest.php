<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Actions\ActivateFreeMonthAction;
use Dmitryisaenko\LaraFoundry\Billing\Events\TrialStarted;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\FreeMonthUnavailableException;
use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCode;
use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCodeRedemption;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The app-side free month (decision D-B5-2): stamps the core trial columns with NO
 * gateway and NO card, per-company eligibility (D-B5-3), monthly-only. The whole
 * grant is asserted without any Stripe/Paddle call — that is the point.
 */
function billingMakeCompany(string $slug = 'acme'): BillableCompany
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
        'country' => 'UA',
        'created_by_id' => $userId,
    ])->save();

    return $company->refresh();
}

it('grants a free month: writes trial and free_month columns and starts a trial', function () {
    Event::fake([TrialStarted::class]);
    $company = billingMakeCompany('fm-grant');

    app(ActivateFreeMonthAction::class)->execute($company, planId: 'pro', period: 'monthly');

    $company->refresh();

    expect($company->free_month_used_at)->not->toBeNull()
        ->and($company->plan_id)->toBe('pro')
        ->and($company->billing_period)->toBe('monthly')
        ->and($company->trial_ends_at->isFuture())->toBeTrue()
        ->and(Carbon::now()->diffInDays($company->trial_ends_at))->toBeGreaterThanOrEqual(29)
        ->and($company->isOnTrial())->toBeTrue();

    Event::assertDispatched(TrialStarted::class);
});

it('blocks a second free month for the same company (per-company eligibility)', function () {
    $company = billingMakeCompany('fm-twice');
    $action = app(ActivateFreeMonthAction::class);

    $action->execute($company, planId: 'pro', period: 'monthly');

    expect(fn () => $action->execute($company->refresh(), planId: 'pro', period: 'monthly'))
        ->toThrow(FreeMonthUnavailableException::class);
});

it('refuses a free month on a yearly plan (monthly-only)', function () {
    $company = billingMakeCompany('fm-yearly');

    expect(fn () => app(ActivateFreeMonthAction::class)->execute($company, planId: 'pro', period: 'yearly'))
        ->toThrow(FreeMonthUnavailableException::class);

    // Nothing was stamped.
    expect($company->refresh()->free_month_used_at)->toBeNull();
});

it('refuses a free month when the offer is disabled in config', function () {
    config()->set('larafoundry-billing.trial.free_month.enabled', false);
    $company = billingMakeCompany('fm-off');

    expect(fn () => app(ActivateFreeMonthAction::class)->execute($company, planId: 'pro', period: 'monthly'))
        ->toThrow(FreeMonthUnavailableException::class);
});

it('refuses to grant a free month for an expired or discount-kind code', function () {
    $company = billingMakeCompany('fm-badcode');
    $action = app(ActivateFreeMonthAction::class);

    // A discount-kind code is not a free-month code.
    $discount = PromoCode::query()->create([
        'code' => 'NOTFREE',
        'kind' => PromoCode::KIND_DISCOUNT,
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'is_active' => true,
    ]);

    expect(fn () => $action->execute($company, planId: 'pro', period: 'monthly', code: $discount, userId: 1))
        ->toThrow(FreeMonthUnavailableException::class);

    // An expired free-month code.
    $expired = PromoCode::query()->create([
        'code' => 'OLDFREE',
        'kind' => PromoCode::KIND_FREE_MONTH,
        'discount_type' => 'percentage',
        'discount_value' => 0,
        'is_active' => true,
        'expires_at' => now()->subDay(),
    ]);

    expect(fn () => $action->execute($company->refresh(), planId: 'pro', period: 'monthly', code: $expired, userId: 1))
        ->toThrow(FreeMonthUnavailableException::class);

    // Nothing was granted and neither code's usage moved.
    expect($company->refresh()->free_month_used_at)->toBeNull()
        ->and($discount->refresh()->used_count)->toBe(0)
        ->and($expired->refresh()->used_count)->toBe(0);
});

it('records a redemption row and increments usage when triggered by a code', function () {
    $company = billingMakeCompany('fm-code');
    $code = PromoCode::query()->create([
        'code' => 'FREEMONTH',
        'kind' => PromoCode::KIND_FREE_MONTH,
        'discount_type' => 'percentage',
        'discount_value' => 0,
        'is_active' => true,
    ]);

    app(ActivateFreeMonthAction::class)->execute(
        $company,
        planId: 'pro',
        period: 'monthly',
        code: $code,
        userId: 42,
    );

    $redemption = PromoCodeRedemption::query()->where('promo_code_id', $code->getKey())->first();

    expect($redemption)->not->toBeNull()
        ->and($redemption->kind)->toBe(PromoCode::KIND_FREE_MONTH)
        ->and($redemption->user_id)->toBe(42)
        ->and($code->refresh()->used_count)->toBe(1);
});
