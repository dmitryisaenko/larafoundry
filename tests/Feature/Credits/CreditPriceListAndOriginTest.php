<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Support\PaymentOrigin;
use Dmitryisaenko\LaraFoundry\Credits\Contracts\CreditPriceList;

function plPrices(): CreditPriceList
{
    return app(CreditPriceList::class);
}

it('puts nothing on sale by default', function () {
    expect(plPrices()->priceCents(100))->toBeNull()
        ->and(plPrices()->bundles())->toBe([])
        ->and(plPrices()->currency())->toBe('USD');
});

it('sells bundles at their price and custom amounts within the range', function () {
    config()->set('larafoundry.credits.purchases.bundles', [
        ['credits' => 10000, 'price_cents' => 1849],
        ['credits' => 5000, 'price_cents' => 969],
    ]);
    config()->set('larafoundry.credits.purchases.custom', ['min' => 2500, 'max' => 50000, 'cents_per_credit' => 0.2]);

    expect(plPrices()->priceCents(5000))->toBe(969)
        ->and(plPrices()->priceCents(2501))->toBe(501)
        ->and(plPrices()->priceCents(50001))->toBeNull()
        ->and(plPrices()->bundles())->toBe([
            ['credits' => 5000, 'price_cents' => 969],
            ['credits' => 10000, 'price_cents' => 1849],
        ]);
});

it('applies the floor to bundles too', function () {
    // A cheap bundle shared with another channel must not slip under a floor the
    // gateway's fixed fee makes loss-making.
    config()->set('larafoundry.credits.purchases.bundles', [['credits' => 1000, 'price_cents' => 200]]);
    config()->set('larafoundry.credits.purchases.custom', ['min' => 2500, 'max' => 50000]);

    expect(plPrices()->priceCents(1000))->toBeNull()
        ->and(plPrices()->bundles())->toBe([]);
});

it('sells only bundles while custom amounts are off', function () {
    config()->set('larafoundry.credits.purchases.bundles', [['credits' => 5000, 'price_cents' => 969]]);

    expect(plPrices()->priceCents(5000))->toBe(969)
        ->and(plPrices()->priceCents(4000))->toBeNull();
});

it('does not overcharge a cent through float rounding', function () {
    config()->set('larafoundry.credits.purchases.custom', ['min' => 1, 'max' => 1000, 'cents_per_credit' => 0.07]);

    expect(plPrices()->priceCents(100))->toBe(7);
});

it('shows and charges the first of two bundles of one size', function () {
    config()->set('larafoundry.credits.purchases.bundles', [
        ['credits' => 5000, 'price_cents' => 969],
        ['credits' => 5000, 'price_cents' => 500],
    ]);

    expect(plPrices()->priceCents(5000))->toBe(969)
        ->and(plPrices()->bundles())->toBe([['credits' => 5000, 'price_cents' => 969]]);
});

it('never sells for nothing', function () {
    config()->set('larafoundry.credits.purchases.bundles', [['credits' => 5000, 'price_cents' => 0]]);
    config()->set('larafoundry.credits.purchases.custom', ['min' => 1, 'max' => 100, 'cents_per_credit' => 0]);

    expect(plPrices()->priceCents(5000))->toBeNull()
        ->and(plPrices()->priceCents(50))->toBeNull();
});

it('prices a custom amount at the credit\'s own value by default', function () {
    config()->set('larafoundry.credits.unit_cents', 0.5);
    config()->set('larafoundry.credits.purchases.custom', ['min' => 1, 'max' => 1000]);

    expect(plPrices()->priceCents(3))->toBe(2);
});

it('stamps nothing and owns everything without an installation name', function () {
    expect(PaymentOrigin::stamp(['a' => 'b']))->toBe(['a' => 'b'])
        ->and(PaymentOrigin::isOurs(['install' => 'elsewhere']))->toBeTrue()
        ->and(PaymentOrigin::isOurs(null))->toBeTrue();
});

it('stamps this installation and owns only its own payments', function () {
    config()->set('larafoundry-billing.install.name', ' Lorevid ');

    expect(PaymentOrigin::stamp(['a' => 'b']))->toBe(['install' => 'lorevid', 'a' => 'b'])
        ->and(PaymentOrigin::stamp(['install' => 'forged']))->toBe(['install' => 'lorevid'])
        ->and(PaymentOrigin::isOurs(['install' => 'LOREVID']))->toBeTrue()
        ->and(PaymentOrigin::isOurs((object) ['install' => 'lorevid']))->toBeTrue()
        ->and(PaymentOrigin::isOurs(['install' => 'commentio']))->toBeFalse()
        ->and(PaymentOrigin::isOurs([]))->toBeFalse();
});

it('gives an unstamped payment to the installation named for it', function () {
    config()->set('larafoundry-billing.install.name', 'comentor');
    config()->set('larafoundry-billing.install.unmarked', 'comentor');

    expect(PaymentOrigin::isOurs([]))->toBeTrue();

    config()->set('larafoundry-billing.install.name', 'lorevid');

    expect(PaymentOrigin::isOurs([]))->toBeFalse();
});
