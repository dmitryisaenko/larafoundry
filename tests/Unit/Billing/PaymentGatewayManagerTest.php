<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Contracts\PaymentGatewayInterface;
use Dmitryisaenko\LaraFoundry\Billing\Support\NullPaymentGateway;
use Dmitryisaenko\LaraFoundry\Billing\Support\PaymentGatewayManager;

/*
 * The gateway manager is the Mail/Queue-style driver seam (phase 3.1). These
 * tests pin: the free core resolves the null driver by default, an unregistered
 * driver fails fast (not a silent fallback that masks a misconfiguration), and
 * the add-on's extend() path works and overrides cleanly.
 */

function manager(): PaymentGatewayManager
{
    return new PaymentGatewayManager(app());
}

it('resolves the null driver by default in the free core', function () {
    config()->set('larafoundry.billing.gateway.default', 'null');

    $driver = manager()->driver();

    expect($driver)->toBeInstanceOf(NullPaymentGateway::class)
        ->and($driver->isConfigured())->toBeFalse();
});

it('falls back to the null driver when no default is configured', function () {
    config()->set('larafoundry.billing.gateway.default', null);

    expect(manager()->defaultDriver())->toBe('null')
        ->and(manager()->driver())->toBeInstanceOf(NullPaymentGateway::class);
});

it('throws for an unregistered driver instead of falling back silently', function () {
    config()->set('larafoundry.billing.gateway.default', 'stripe');

    expect(fn () => manager()->driver())
        ->toThrow(InvalidArgumentException::class, 'stripe');
});

it('lets an add-on register a real driver via extend', function () {
    $fake = new class implements PaymentGatewayInterface
    {
        public function name(): string
        {
            return 'fake';
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function subscribe($tenant, string $planId, string $period, array $options = []): array
        {
            return ['ok' => true];
        }

        public function cancel($tenant, bool $atPeriodEnd = true): void {}

        public function refund(string $chargeReference, ?int $amount = null): void {}

        public function subscriptionStatus($tenant): string
        {
            return 'active';
        }

        public function verifyWebhook($request): array
        {
            return [];
        }
    };

    $manager = manager()->extend('fake', fn () => $fake);
    config()->set('larafoundry.billing.gateway.default', 'fake');

    expect($manager->driver())->toBe($fake)
        ->and($manager->isConfigured())->toBeTrue()
        ->and($manager->availableDrivers())->toContain('fake', 'null');
});

it('memoises a resolved driver', function () {
    $manager = manager();

    expect($manager->driver('null'))->toBe($manager->driver('null'));
});

it('rebuilds a driver after it is re-registered via extend', function () {
    $manager = manager();
    $first = $manager->driver('null');

    $replacement = new NullPaymentGateway;
    $manager->extend('null', fn () => $replacement);

    expect($manager->driver('null'))->toBe($replacement)
        ->and($manager->driver('null'))->not->toBe($first);
});
