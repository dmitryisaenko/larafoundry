<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Gateways\CashierPaddleDriver;
use Dmitryisaenko\LaraFoundry\Billing\Support\PaymentGatewayManager;

/**
 * Proves the add-on registers the Paddle driver against the core's manager through
 * the same config-driven extend() seam as Stripe, and that it coexists with the
 * null driver (and, by extension, the Stripe driver) without conflict.
 */
it('registers the paddle driver against the core manager', function () {
    $manager = app(PaymentGatewayManager::class);

    expect($manager->availableDrivers())->toContain('paddle')
        ->and($manager->driver('paddle'))->toBeInstanceOf(CashierPaddleDriver::class)
        ->and($manager->driver('paddle')->name())->toBe('paddle');
});

it('reports the paddle driver as configured with the test credentials', function () {
    expect(app(PaymentGatewayManager::class)->driver('paddle')->isConfigured())->toBeTrue();
});

it('keeps the core null driver available alongside paddle', function () {
    $manager = app(PaymentGatewayManager::class);

    expect($manager->availableDrivers())->toContain('null')
        ->and($manager->driver('null')->isConfigured())->toBeFalse();
});
