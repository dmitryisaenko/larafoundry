<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Support\PaymentGatewayManager;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\StubGateway;

it('registers a configured gateway driver against the core manager via extend()', function () {
    $manager = app(PaymentGatewayManager::class);

    expect($manager->availableDrivers())->toContain('stub')
        ->and($manager->driver('stub'))->toBeInstanceOf(StubGateway::class)
        ->and($manager->driver('stub')->isConfigured())->toBeTrue();
});

it('keeps the core null driver alongside the add-on driver', function () {
    $manager = app(PaymentGatewayManager::class);

    expect($manager->availableDrivers())->toContain('null')->toContain('stub');
});
