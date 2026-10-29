<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Contracts\EntitlementResolver;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanContract;
use Dmitryisaenko\LaraFoundry\Billing\LaraFoundryBilling;
use Dmitryisaenko\LaraFoundry\Billing\Support\ConfiguredRegionContext;
use Dmitryisaenko\LaraFoundry\Billing\Support\NullEntitlementResolver;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;

/**
 * End-to-end proof of the B2 rebinding: drive the core's public facade (the API
 * call sites use) against the SHIPPED add-on config, not a test-injected one. This
 * exercises the whole stack — facade -> rebound contract -> config reader -> the
 * example plans in config/larafoundry-billing.php — and so also guards that the
 * shipped config is well-formed.
 */
function companyOnPlan(string $planId): Company
{
    return (new Company)->forceFill(['plan_id' => $planId, 'country' => 'UA']);
}

it('resolves the add-on region context through the core facade', function () {
    expect(LaraFoundryBilling::region())->toBeInstanceOf(ConfiguredRegionContext::class);
});

it('resolves a tenant plan through the facade from the shipped config', function () {
    $plan = LaraFoundryBilling::planFor(companyOnPlan('pro'));

    expect($plan)->toBeInstanceOf(PlanContract::class)
        ->and($plan->id())->toBe('pro')
        ->and($plan->name())->toBe('Pro')
        ->and($plan->features())->toContain('api.access');
});

it('prices the shipped pro plan per currency through the contract', function () {
    $plan = LaraFoundryBilling::planFor(companyOnPlan('pro'));

    expect($plan->priceFor('monthly', 'USD'))->toBe(1900)
        ->and($plan->priceFor('yearly', 'UAH'))->toBe(790000);
});

it('treats the shipped free plan as unpriced', function () {
    $plan = LaraFoundryBilling::planFor(companyOnPlan('free'));

    expect($plan->id())->toBe('free')
        ->and($plan->priceFor('monthly', 'USD'))->toBeNull();
});

it('returns null for a company on no plan, fail-closed', function () {
    expect(LaraFoundryBilling::planFor(new Company))->toBeNull();
});

it('routes a UA tenant to its shipped currency through the region context', function () {
    // The shipped config maps UA -> UAH; this proves country (server-side) ->
    // currency end to end.
    expect(LaraFoundryBilling::region()->currencyFor(companyOnPlan('pro')))->toBe('UAH');
});

it('keeps entitlements open by default when billing is disabled', function () {
    // The contracts resolve per call (v0.45.0): with billing switched off the seam
    // answers with the open NullEntitlementResolver — mirroring how
    // Company::hasAccess() stays open — so a host that has not switched billing on is
    // never gated. The real plan-feature gating is covered in Feature/Entitlements.
    config()->set('larafoundry.billing.enabled', false);

    expect(app(EntitlementResolver::class))->toBeInstanceOf(NullEntitlementResolver::class)
        ->and(LaraFoundryBilling::allows(companyOnPlan('free'), 'production.module'))->toBeTrue();
});
