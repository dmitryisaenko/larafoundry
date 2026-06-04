<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Exceptions\WebhookVerificationException;
use Dmitryisaenko\LaraFoundry\Billing\Support\ArrayPlanRepository;
use Dmitryisaenko\LaraFoundry\Billing\Support\DefaultRegionContext;
use Dmitryisaenko\LaraFoundry\Billing\Support\NullEntitlementResolver;
use Dmitryisaenko\LaraFoundry\Billing\Support\NullPaymentGateway;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/*
 * The free core's default billing implementations keep the app fully OPEN while
 * billing is off, and the null gateway refuses every money operation loudly
 * (the donor's `$paymentStatus = 'success'` bug we are NOT carrying). These pin
 * that behaviour without a database.
 */

/**
 * A bare tenant with an optional country, so the region default can be exercised
 * without booting Eloquent.
 */
function fakeTenant(?string $country = null): Tenant
{
    // No custom constructor: Eloquent instantiates models with `new static()`,
    // so the country is filled afterwards via forceFill.
    $tenant = new class extends Model implements Tenant
    {
        protected $guarded = [];

        public function getTenantKey(): int|string
        {
            return 1;
        }
    };

    return $tenant->forceFill(['country' => $country]);
}

it('null gateway is not configured and refuses to take money', function () {
    $gateway = new NullPaymentGateway;

    expect($gateway->name())->toBe('null')
        ->and($gateway->isConfigured())->toBeFalse()
        ->and($gateway->subscriptionStatus(fakeTenant()))->toBe('none');

    expect(fn () => $gateway->subscribe(fakeTenant(), 'pro', 'monthly'))
        ->toThrow(RuntimeException::class);
    expect(fn () => $gateway->cancel(fakeTenant()))
        ->toThrow(RuntimeException::class);
    expect(fn () => $gateway->refund('ch_1'))
        ->toThrow(RuntimeException::class);
});

it('null gateway rejects every webhook (nothing can be authentic with no provider)', function () {
    expect(fn () => (new NullPaymentGateway)->verifyWebhook(Request::create('/hook', 'POST')))
        ->toThrow(WebhookVerificationException::class);
});

it('default region reads country from the tenant and never trusts a client value', function () {
    config()->set('larafoundry.billing.region.default_currency', 'EUR');
    $region = new DefaultRegionContext;

    expect($region->countryFor(fakeTenant('UA')))->toBe('UA')
        ->and($region->countryFor(fakeTenant(null)))->toBeNull()
        ->and($region->currencyFor(fakeTenant('UA')))->toBe('EUR')
        // The free core never routes by region — always the configured default.
        ->and($region->gatewayFor(fakeTenant('UA')))->toBeNull();
});

it('default entitlement resolver allows every feature in the free core', function () {
    expect((new NullEntitlementResolver)->allows(fakeTenant(), 'reports.export'))->toBeTrue();
});

it('default plan repository knows no plans (billing off out of the box)', function () {
    $repo = new ArrayPlanRepository;

    expect($repo->all())->toBe([])
        ->and($repo->find('pro'))->toBeNull();
});
