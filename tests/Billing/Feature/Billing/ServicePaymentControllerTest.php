<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Events\TrialStarted;
use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCode;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\CheckoutGateway;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\PaymentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The owner billing controller: renders pricing/checkout/history, activates the app-side
 * free month, and — crucially, unlike the donor's fake success — hands a paid plan to
 * the gateway's hosted checkout and redirects there (the webhook records the payment).
 */
function billingActor(string $slug, array $companyAttrs = []): array
{
    $owner = PaymentUser::query()->create([
        'name' => 'Owner',
        'email' => "owner+{$slug}@example.test",
        'password' => bcrypt('secret'),
    ]);

    $company = new BillableCompany;
    $company->forceFill(array_merge([
        'uuid' => (string) Str::uuid(),
        'name' => 'Acme',
        'slug' => $slug,
        'country' => 'ZZ', // unmapped → region falls back to the default gateway
        'created_by_id' => $owner->getKey(),
    ], $companyAttrs))->save();

    $company->refresh();
    $owner->activeCompany = $company;

    return [$company, $owner];
}

beforeEach(fn () => CheckoutGateway::$lastOptions = []);

it('renders the pricing page with plans and currency', function () {
    [, $owner] = billingActor('pricing');

    $this->actingAs($owner)
        ->get('/billing/pricing', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Billing/Pricing')
        ->assertJsonPath('props.gatewayConfigured', true)
        ->assertJsonStructure(['props' => ['plans', 'currency']]);
});

it('renders the checkout page for a valid plan', function () {
    [, $owner] = billingActor('checkout');

    $this->actingAs($owner)
        ->get('/billing/checkout?plan=pro&period=yearly', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Billing/Checkout')
        ->assertJsonPath('props.period', 'yearly')
        ->assertJsonPath('props.plan.id', 'pro');
});

it('redirects checkout back to pricing for an unknown plan', function () {
    [, $owner] = billingActor('checkout-bad');

    $this->actingAs($owner)
        ->get('/billing/checkout?plan=ghost&period=monthly')
        ->assertRedirect(route('billing.pricing'));
});

it('renders the payment history page', function () {
    [, $owner] = billingActor('history');

    $this->actingAs($owner)
        ->get('/billing/history', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Billing/PaymentHistory')
        ->assertJsonStructure(['props' => ['payments']]);
});

it('activates the app-side free month and redirects to history', function () {
    Event::fake([TrialStarted::class]);
    [$company, $owner] = billingActor('free');

    $this->actingAs($owner)
        ->post('/billing/payment', [
            'plan_id' => 'pro',
            'billing_period' => 'monthly',
            'use_free_month' => true,
        ])
        ->assertRedirect(route('billing.history'));

    expect($company->fresh()->trial_ends_at)->not->toBeNull()
        ->and($company->fresh()->free_month_used_at)->not->toBeNull();

    Event::assertDispatched(TrialStarted::class);
});

it('hands a paid plan to the gateway checkout and redirects away', function () {
    [, $owner] = billingActor('paid');

    $this->actingAs($owner)
        ->post('/billing/payment', ['plan_id' => 'pro', 'billing_period' => 'monthly'])
        ->assertRedirect('https://checkout.test/pro/monthly');
});

it('rejects a tampered plan id at validation', function () {
    [, $owner] = billingActor('tamper');

    $this->actingAs($owner)
        ->post('/billing/payment', ['plan_id' => 'ghost', 'billing_period' => 'monthly'])
        ->assertSessionHasErrors('plan_id');
});

it('rejects an invalid promo code', function () {
    [, $owner] = billingActor('badpromo');

    $this->actingAs($owner)
        ->post('/billing/payment', [
            'plan_id' => 'pro',
            'billing_period' => 'monthly',
            'promo_code' => 'NOPE',
        ])
        ->assertSessionHasErrors('promo_code');
});

it('passes a mapped promo coupon to the gateway', function () {
    [, $owner] = billingActor('goodpromo');
    PromoCode::query()->create([
        'code' => 'SAVE20',
        'kind' => PromoCode::KIND_DISCOUNT,
        'discount_type' => 'percentage',
        'discount_value' => 20,
        'single_use_per_user' => true,
        'is_active' => true,
        'stripe_coupon_id' => 'coupon_save20',
    ]);

    $this->actingAs($owner)
        ->post('/billing/payment', [
            'plan_id' => 'pro',
            'billing_period' => 'monthly',
            'promo_code' => 'SAVE20',
        ])
        ->assertRedirect('https://checkout.test/pro/monthly');

    expect(CheckoutGateway::$lastOptions)->toBe(['stripe_coupon' => 'coupon_save20']);
});

it('errors back when there is no active company', function () {
    $orphan = PaymentUser::query()->create([
        'name' => 'Orphan',
        'email' => 'orphan@example.test',
        'password' => bcrypt('secret'),
    ]);
    // activeCompany stays null.

    $this->actingAs($orphan)
        ->post('/billing/payment', ['plan_id' => 'pro', 'billing_period' => 'monthly'])
        ->assertSessionHasErrors('error');
});
