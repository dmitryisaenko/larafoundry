<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Contracts\RegionContext;
use Dmitryisaenko\LaraFoundry\Billing\Support\ConfiguredRegionContext;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;

/**
 * A tenant carrying a country, built without touching the database — the resolver
 * only reads the `country` attribute via getAttribute(), so forceFill is enough.
 */
function tenantInCountry(?string $country): Company
{
    $company = new Company;

    if ($country !== null) {
        $company->forceFill(['country' => $country]);
    }

    return $company;
}

/** A non-Eloquent tenant — exercises the instanceof Model guard. */
function plainTenant(): Tenant
{
    return new class implements Tenant
    {
        public function getTenantKey(): int|string
        {
            return 'plain';
        }
    };
}

beforeEach(function () {
    config()->set('larafoundry-billing.region.currencies', [
        'US' => 'USD',
        'UA' => 'UAH',
        'DE' => 'EUR',
    ]);
    config()->set('larafoundry-billing.region.gateways', [
        'UA' => 'paddle',
    ]);
    config()->set('larafoundry.billing.region.default_currency', 'USD');
});

it('binds the contract to the configured region context', function () {
    expect(app(RegionContext::class))->toBeInstanceOf(ConfiguredRegionContext::class);
});

it('reads the country server-side from the tenant column', function () {
    expect(app(RegionContext::class)->countryFor(tenantInCountry('UA')))->toBe('UA');
});

it('returns null country for a tenant with no country', function () {
    expect(app(RegionContext::class)->countryFor(tenantInCountry(null)))->toBeNull();
});

it('returns null country for a non-Eloquent tenant', function () {
    expect(app(RegionContext::class)->countryFor(plainTenant()))->toBeNull();
});

it('maps a known country to its configured currency', function () {
    $region = app(RegionContext::class);

    expect($region->currencyFor(tenantInCountry('UA')))->toBe('UAH')
        ->and($region->currencyFor(tenantInCountry('DE')))->toBe('EUR');
});

it('falls back to the core default currency for an unmapped country', function () {
    expect(app(RegionContext::class)->currencyFor(tenantInCountry('JP')))->toBe('USD');
});

it('falls back to the core default currency for an unknown tenant', function () {
    expect(app(RegionContext::class)->currencyFor(tenantInCountry(null)))->toBe('USD');
});

it('honours a changed core default currency on fallback', function () {
    config()->set('larafoundry.billing.region.default_currency', 'GBP');

    expect(app(RegionContext::class)->currencyFor(tenantInCountry('JP')))->toBe('GBP');
});

it('defends against a non-string core default currency', function () {
    config()->set('larafoundry.billing.region.default_currency', null);

    expect(app(RegionContext::class)->currencyFor(tenantInCountry('JP')))->toBe('USD');
});

it('routes a mapped country to its configured gateway', function () {
    expect(app(RegionContext::class)->gatewayFor(tenantInCountry('UA')))->toBe('paddle');
});

it('returns null gateway for an unmapped country, deferring to the default', function () {
    expect(app(RegionContext::class)->gatewayFor(tenantInCountry('US')))->toBeNull();
});

it('returns null gateway for an unknown tenant', function () {
    expect(app(RegionContext::class)->gatewayFor(tenantInCountry(null)))->toBeNull();
});

it('treats a country containing a dot as a flat-map miss, not a nested path', function () {
    // The map is read as an array and indexed by the raw key, so a country like
    // 'U.A' cannot make config() walk a nested tree — it simply misses and falls
    // back. Guards against dot-path traversal from an unvalidated country column.
    config()->set('larafoundry-billing.region.currencies', ['U.A' => 'UAH']);

    $region = app(RegionContext::class);

    expect($region->currencyFor(tenantInCountry('U.A')))->toBe('UAH')
        ->and($region->currencyFor(tenantInCountry('U')))->toBe('USD');
});
