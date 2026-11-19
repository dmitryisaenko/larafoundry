<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Credits\Contracts\CreditPriceList;
use Dmitryisaenko\LaraFoundry\Credits\Contracts\CreditPriceLists;
use Dmitryisaenko\LaraFoundry\Credits\Contracts\CreditPurchaseRoutes;
use Dmitryisaenko\LaraFoundry\Credits\Support\CreditPurchaseRoute;
use Dmitryisaenko\LaraFoundry\Support\RequestCountry;
use Illuminate\Http\Request;

function rcRequest(?string $country, string $header = 'CF-IPCountry'): Request
{
    $request = Request::create('/');

    if ($country !== null) {
        $request->headers->set($header, $country);
    }

    return $request;
}

it('reads the country the proxy reports', function (?string $sent, ?string $expected) {
    expect(app(RequestCountry::class)->of(rcRequest($sent)))->toBe($expected);
})->with([
    'ukraine' => ['UA', 'UA'],
    'lower case and spaces' => [' ua ', 'UA'],
    'none' => [null, null],
    'cloudflare could not tell' => ['XX', null],
    'tor' => ['T1', null],
    'not a code' => ['UKR', null],
    'empty' => ['', null],
]);

it('reads another header when told, and nothing when switched off', function () {
    config()->set('larafoundry.request_country.header', 'X-Country');

    expect(app(RequestCountry::class)->of(rcRequest('ua', 'X-Country')))->toBe('UA')
        ->and(app(RequestCountry::class)->of(rcRequest('UA')))->toBeNull();

    config()->set('larafoundry.request_country.header', '');

    expect(app(RequestCountry::class)->of(rcRequest('UA', '')))->toBeNull();
});

it('routes a listed country to its gateway and everyone else to the default', function () {
    config()->set('larafoundry.credits.purchases.routes', [
        'default' => ['gateway' => 'paddle'],
        'countries' => [
            'ua' => ['gateway' => 'liqpay', 'currency' => 'uah'],
            'US' => ['gateway' => 'liqpay', 'bonus_percent' => 5],
            'PL' => ['currency' => 'PLN'],
        ],
    ]);

    $routes = app(CreditPurchaseRoutes::class);

    expect($routes->for('UA'))->toMatchObject(['gateway' => 'liqpay', 'currency' => 'UAH', 'bonusPercent' => 0])
        ->and($routes->for('us'))->toMatchObject(['gateway' => 'liqpay', 'currency' => null, 'bonusPercent' => 5])
        // An entry without a gateway, an unlisted country, an unknown one: the default.
        ->and($routes->for('PL')->gateway)->toBe('paddle')
        ->and($routes->for('DE')->gateway)->toBe('paddle')
        ->and($routes->for(null)->gateway)->toBe('paddle')
        ->and($routes->for('U.A')->gateway)->toBe('paddle');
});

it('falls back to paddle with no routes configured at all', function () {
    config()->set('larafoundry.credits.purchases.routes', null);

    expect(app(CreditPurchaseRoutes::class)->for('UA'))->toMatchObject(['gateway' => 'paddle', 'currency' => null, 'bonusPercent' => 0]);
});

it('clamps a bonus out of range in the settings and adds it rounded down', function () {
    expect(CreditPurchaseRoute::fromArray(['gateway' => 'liqpay', 'bonus_percent' => 250])?->bonusPercent)->toBe(100)
        ->and(CreditPurchaseRoute::fromArray(['gateway' => 'liqpay', 'bonus_percent' => -3])?->bonusPercent)->toBe(0)
        ->and(CreditPurchaseRoute::fromArray(['currency' => 'UAH']))->toBeNull()
        ->and((new CreditPurchaseRoute('liqpay', null, 5))->creditsFor(2510))->toBe(2635)
        ->and((new CreditPurchaseRoute('liqpay'))->creditsFor(2510))->toBe(2510);

    expect(fn () => new CreditPurchaseRoute('liqpay', null, 101))->toThrow(InvalidArgumentException::class);
});

it('gives the main list for its own currency and a list of its own for another', function () {
    config()->set('larafoundry.credits.purchases.currency', 'USD');
    config()->set('larafoundry.credits.purchases.bundles', [['credits' => 5000, 'price_cents' => 969]]);
    config()->set('larafoundry.credits.purchases.custom', ['min' => 2500, 'max' => 50000, 'cents_per_credit' => 0.2]);
    config()->set('larafoundry.credits.purchases.currencies', [
        'UAH' => [
            'bundles' => [['credits' => 5000, 'price_cents' => 39900], ['credits' => 1000, 'price_cents' => 9900]],
            'custom' => ['min' => 2500, 'max' => 50000],
        ],
        'EUR' => ['bundles' => [], 'custom' => ['min' => 1, 'max' => 50000, 'cents_per_credit' => 0.19]],
    ]);

    $lists = app(CreditPriceLists::class);

    expect($lists->in(null))->toBeInstanceOf(CreditPriceList::class)
        ->and($lists->in(null)?->currency())->toBe('USD')
        ->and($lists->in(null)?->priceCents(5000))->toBe(969)
        ->and($lists->in('usd')?->priceCents(7000))->toBe(1400)
        ->and($lists->in('PLN'))->toBeNull()
        ->and($lists->in('U.AH'))->toBeNull();

    $uah = $lists->in('uah');

    expect($uah?->currency())->toBe('UAH')
        ->and($uah->priceCents(5000))->toBe(39900)
        // Below the list's own floor, bundle or not.
        ->and($uah->priceCents(1000))->toBeNull()
        ->and($uah->bundles())->toBe([['credits' => 5000, 'price_cents' => 39900]])
        // No per-credit price of its own: the credit's value is in dollars, so no custom amounts.
        ->and($uah->priceCents(7000))->toBeNull();

    expect($lists->in('EUR')?->priceCents(100))->toBe(19);
});
