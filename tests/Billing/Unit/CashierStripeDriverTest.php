<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Contracts\PaymentGatewayInterface;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\GatewayOperationException;
use Dmitryisaenko\LaraFoundry\Billing\Gateways\CashierStripeDriver;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Illuminate\Config\Repository;

/**
 * Pure unit tests for the Stripe driver: identity, the isConfigured() guard and
 * the plan ↔ price-id mapping. No network, no database — the driver is built with
 * an in-memory config repository so these assertions never touch Stripe.
 */
function stripeDriver(array $config = []): CashierStripeDriver
{
    return new CashierStripeDriver(new Repository(array_merge_recursive([
        'larafoundry-billing' => [
            'billable_model' => BillableCompany::class,
            'stripe' => ['secret' => 'sk_test_dummy'],
            'plans' => [
                'definitions' => [
                    'pro' => [
                        'gateways' => ['stripe' => [
                            'monthly' => 'price_pro_monthly',
                            'yearly' => 'price_pro_yearly',
                        ]],
                    ],
                ],
            ],
        ],
    ], $config)));
}

it('reports its driver name', function () {
    expect(stripeDriver()->name())->toBe('stripe')
        ->and(stripeDriver())->toBeInstanceOf(PaymentGatewayInterface::class);
});

it('is configured when a secret is set and the billable model is Billable', function () {
    expect(stripeDriver()->isConfigured())->toBeTrue();
});

it('is not configured without a Stripe secret', function () {
    $driver = stripeDriver(['larafoundry-billing' => ['stripe' => ['secret' => null]]]);

    expect($driver->isConfigured())->toBeFalse();
});

it('is not configured when the billable model is the gateway-agnostic core Company', function () {
    // The default billable_model is the core Company, which has no Billable trait.
    // The driver must degrade to "not connected" rather than fatal on a Cashier call.
    $driver = new CashierStripeDriver(new Repository([
        'larafoundry-billing' => [
            'billable_model' => Company::class,
            'stripe' => ['secret' => 'sk_test_dummy'],
        ],
    ]));

    expect($driver->isConfigured())->toBeFalse();
});

it('falls back to the cashier.secret when the add-on secret is empty', function () {
    $driver = new CashierStripeDriver(new Repository([
        'larafoundry-billing' => ['billable_model' => BillableCompany::class, 'stripe' => ['secret' => null]],
        'cashier' => ['secret' => 'sk_test_from_cashier'],
    ]));

    expect($driver->isConfigured())->toBeTrue();
});

it('resolves the Stripe price id for a plan and period', function () {
    expect(stripeDriver()->planIdForPrice('price_pro_monthly'))->toBe('pro')
        ->and(stripeDriver()->planIdForPrice('price_pro_yearly'))->toBe('pro');
});

it('returns null when no plan claims a price id', function () {
    expect(stripeDriver()->planIdForPrice('price_unknown'))->toBeNull();
});

it('throws when no Stripe price id is mapped for the plan and period', function () {
    // priceId() is private; exercise it through subscribe(), which must fail loudly
    // before charging rather than send a wrong/zero amount to Stripe.
    $company = (new BillableCompany)->forceFill(['id' => 'c1']);

    stripeDriver()->subscribe($company, 'business', 'monthly');
})->throws(GatewayOperationException::class);

/**
 * Reach the private checkoutDiscount() (a pure function of subscribe()'s $options)
 * without a network Checkout. It encodes the B5 hybrid + Stripe's discounts vs
 * allow_promotion_codes mutual exclusion, which is exactly what needs proving.
 */
function stripeCheckoutDiscount(array $options): ?array
{
    $method = new ReflectionMethod(CashierStripeDriver::class, 'checkoutDiscount');

    return $method->invoke(stripeDriver(), $options);
}

it('builds a Checkout discounts entry from a mapped promotion code', function () {
    // A registry code maps to a Stripe promotion code id; Stripe applies and
    // computes the discount — the driver never computes the amount.
    expect(stripeCheckoutDiscount(['stripe_promotion_code' => 'promo_x']))
        ->toBe(['promotion_code' => 'promo_x']);
});

it('builds a Checkout discounts entry from a raw coupon id', function () {
    expect(stripeCheckoutDiscount(['stripe_coupon' => 'cpn_y']))
        ->toBe(['coupon' => 'cpn_y']);
});

it('prefers a promotion code over a raw coupon when both are given', function () {
    expect(stripeCheckoutDiscount(['stripe_promotion_code' => 'promo_x', 'stripe_coupon' => 'cpn_y']))
        ->toBe(['promotion_code' => 'promo_x']);
});

it('yields no discount entry when no mapped code is supplied', function () {
    // Null => subscribe() falls back to allowPromotionCodes() if options.promo set.
    // The two are mutually exclusive in Stripe Checkout, which is why this returns
    // null rather than an empty entry.
    expect(stripeCheckoutDiscount([]))->toBeNull()
        ->and(stripeCheckoutDiscount(['stripe_coupon' => '']))->toBeNull()
        ->and(stripeCheckoutDiscount(['promo' => true]))->toBeNull();
});
