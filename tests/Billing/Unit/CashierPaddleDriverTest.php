<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Contracts\PaymentGatewayInterface;
use Dmitryisaenko\LaraFoundry\Billing\Exceptions\GatewayOperationException;
use Dmitryisaenko\LaraFoundry\Billing\Gateways\CashierPaddleDriver;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\PaddleBillableCompany;
use Illuminate\Config\Repository;

/**
 * Pure unit tests for the Paddle driver: identity, the isConfigured() guard, the
 * plan ↔ price-id mapping and the deferred-refund stub. No network, no database —
 * the driver is built with an in-memory config so these never touch Paddle.
 */
function paddleDriver(array $config = []): CashierPaddleDriver
{
    return new CashierPaddleDriver(new Repository(array_merge_recursive([
        'larafoundry-billing' => [
            'paddle_billable_model' => PaddleBillableCompany::class,
            'paddle' => ['api_key' => 'pdl_test_dummy'],
            'plans' => [
                'definitions' => [
                    'pro' => [
                        'gateways' => ['paddle' => [
                            'monthly' => 'pri_pro_monthly',
                            'yearly' => 'pri_pro_yearly',
                        ]],
                    ],
                ],
            ],
        ],
    ], $config)));
}

it('reports its driver name', function () {
    expect(paddleDriver()->name())->toBe('paddle')
        ->and(paddleDriver())->toBeInstanceOf(PaymentGatewayInterface::class);
});

it('is configured when an api key is set and the billable model is Paddle-Billable', function () {
    expect(paddleDriver()->isConfigured())->toBeTrue();
});

it('is not configured without a Paddle api key', function () {
    $driver = paddleDriver(['larafoundry-billing' => ['paddle' => ['api_key' => null]]]);

    expect($driver->isConfigured())->toBeFalse();
});

it('is not configured when the billable model is the gateway-agnostic core Company', function () {
    // The core Company has no Paddle Billable trait. The driver must degrade to
    // "not connected" rather than fatal on a Cashier-Paddle call.
    $driver = new CashierPaddleDriver(new Repository([
        'larafoundry-billing' => [
            'paddle_billable_model' => Company::class,
            'paddle' => ['api_key' => 'pdl_test_dummy'],
        ],
    ]));

    expect($driver->isConfigured())->toBeFalse();
});

it('falls back to the cashier.api_key when the add-on key is empty', function () {
    $driver = new CashierPaddleDriver(new Repository([
        'larafoundry-billing' => ['paddle_billable_model' => PaddleBillableCompany::class, 'paddle' => ['api_key' => null]],
        'cashier' => ['api_key' => 'pdl_from_cashier'],
    ]));

    expect($driver->isConfigured())->toBeTrue();
});

it('resolves the Paddle price id back to a plan', function () {
    expect(paddleDriver()->planIdForPrice('pri_pro_monthly'))->toBe('pro')
        ->and(paddleDriver()->planIdForPrice('pri_pro_yearly'))->toBe('pro');
});

it('returns null when no plan claims a price id', function () {
    expect(paddleDriver()->planIdForPrice('pri_unknown'))->toBeNull();
});

it('throws when no Paddle price id is mapped for the plan and period', function () {
    // priceId() is private; exercise it through subscribe(), which must fail loudly
    // before creating a customer rather than charge a wrong/missing price.
    $company = (new PaddleBillableCompany)->forceFill(['id' => 'c1']);

    paddleDriver()->subscribe($company, 'business', 'monthly');
})->throws(GatewayOperationException::class);

it('refuses programmatic refunds (deferred to a later sub-phase)', function () {
    // Paddle is the merchant of record; refunds go through Paddle, not code (D-B4-3).
    paddleDriver()->refund('txn_123');
})->throws(GatewayOperationException::class, 'merchant of record');

/**
 * Reach the private withDiscount() (a pure function of the overlay options) without
 * a network Checkout. It proves the B5 discount id is injected into the payload the
 * host's JS feeds to Paddle.Checkout.open().
 */
function paddleWithDiscount(array $checkoutOptions, array $options): array
{
    $method = new ReflectionMethod(CashierPaddleDriver::class, 'withDiscount');

    return $method->invoke(paddleDriver(), $checkoutOptions, $options);
}

it('injects the mapped discount id into the overlay payload', function () {
    expect(paddleWithDiscount(['items' => []], ['paddle_discount_id' => 'dsc_x']))
        ->toBe(['items' => [], 'discountId' => 'dsc_x']);
});

it('leaves the overlay payload untouched when no discount id is supplied', function () {
    expect(paddleWithDiscount(['items' => []], []))->toBe(['items' => []])
        ->and(paddleWithDiscount(['items' => []], ['paddle_discount_id' => '']))->toBe(['items' => []]);
});
