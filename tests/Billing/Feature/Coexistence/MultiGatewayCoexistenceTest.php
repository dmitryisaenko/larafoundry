<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Contracts\RegionContext;
use Dmitryisaenko\LaraFoundry\Billing\Gateways\CashierPaddleDriver;
use Dmitryisaenko\LaraFoundry\Billing\Gateways\CashierStripeDriver;
use Dmitryisaenko\LaraFoundry\Billing\Support\PaymentGatewayManager;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\PaddleBillableCompany;
use Laravel\Cashier\Billable as StripeBillable;
use Laravel\Paddle\Billable as PaddleBillable;

/**
 * Proves decision D-B4-1's coexistence claim: a single host can run Stripe and
 * Paddle side by side, with two Company subclasses (one per gateway, because the
 * two Cashier `Billable` traits collide and cannot share a class), and route
 * tenants between them by region — exactly what B2's region.gateways was built for.
 */
it('registers both gateway drivers against the one core manager', function () {
    $manager = app(PaymentGatewayManager::class);

    expect($manager->availableDrivers())->toContain('stripe')->toContain('paddle')
        ->and($manager->driver('stripe'))->toBeInstanceOf(CashierStripeDriver::class)
        ->and($manager->driver('paddle'))->toBeInstanceOf(CashierPaddleDriver::class);
});

it('reports both drivers as configured with their own credentials', function () {
    $manager = app(PaymentGatewayManager::class);

    expect($manager->driver('stripe')->isConfigured())->toBeTrue()
        ->and($manager->driver('paddle')->isConfigured())->toBeTrue();
});

it('keeps the two billable subclasses on distinct, non-colliding Billable traits', function () {
    // The whole reason for two subclasses: each carries exactly one gateway's
    // Billable trait, never both.
    expect(in_array(StripeBillable::class, class_uses_recursive(BillableCompany::class), true))->toBeTrue()
        ->and(in_array(PaddleBillable::class, class_uses_recursive(BillableCompany::class), true))->toBeFalse()
        ->and(in_array(PaddleBillable::class, class_uses_recursive(PaddleBillableCompany::class), true))->toBeTrue()
        ->and(in_array(StripeBillable::class, class_uses_recursive(PaddleBillableCompany::class), true))->toBeFalse();
});

it('routes a UA tenant to paddle and everyone else to the default gateway', function () {
    $region = app(RegionContext::class);

    $uaTenant = (new PaddleBillableCompany)->forceFill(['country' => 'UA']);
    $usTenant = (new BillableCompany)->forceFill(['country' => 'US']);

    expect($region->gatewayFor($uaTenant))->toBe('paddle')
        // Unmapped country → null = "use the configured default gateway".
        ->and($region->gatewayFor($usTenant))->toBeNull();
});
