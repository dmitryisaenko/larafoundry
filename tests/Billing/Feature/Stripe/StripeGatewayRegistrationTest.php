<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Gateways\CashierStripeDriver;
use Dmitryisaenko\LaraFoundry\Billing\Support\NullPaymentGateway;
use Dmitryisaenko\LaraFoundry\Billing\Support\PaymentGatewayManager;

it('registers the Stripe driver against the core manager via extend()', function () {
    $manager = app(PaymentGatewayManager::class);

    expect($manager->availableDrivers())->toContain('stripe')
        ->and($manager->driver('stripe'))->toBeInstanceOf(CashierStripeDriver::class)
        ->and($manager->driver('stripe')->isConfigured())->toBeTrue();
});

it('keeps the core null driver alongside the Stripe driver', function () {
    $manager = app(PaymentGatewayManager::class);

    expect($manager->driver('null'))->toBeInstanceOf(NullPaymentGateway::class)
        ->and($manager->availableDrivers())->toContain('null')->toContain('stripe');
});
