<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Contracts\EntitlementResolver;
use Dmitryisaenko\LaraFoundry\Billing\LaraFoundryBilling;
use Dmitryisaenko\LaraFoundry\Billing\Support\PlanEntitlementResolver;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;

/**
 * The PlanEntitlementResolver: membership in the tenant's plan features, fail-closed
 * when billing is on and the plan is missing/unknown, fully open when billing is off.
 * Plans come from the shipped example config (free/pro/business); the tenant's plan is
 * set in-memory with forceFill, so no database is involved.
 */
function entitlementCompanyOnPlan(?string $planId): BillableCompany
{
    $company = new BillableCompany;

    if ($planId !== null) {
        $company->forceFill(['plan_id' => $planId]);
    }

    return $company;
}

it('rebinds the core resolver to the plan resolver', function () {
    expect(app(EntitlementResolver::class))->toBeInstanceOf(PlanEntitlementResolver::class);
});

it('grants a feature the plan includes', function () {
    $allowed = app(EntitlementResolver::class)->allows(entitlementCompanyOnPlan('business'), 'production.module');

    expect($allowed)->toBeTrue();
});

it('denies a feature the plan does not include but grants one it does', function () {
    $resolver = app(EntitlementResolver::class);
    $company = entitlementCompanyOnPlan('free');

    expect($resolver->allows($company, 'production.module'))->toBeFalse()
        ->and($resolver->allows($company, 'core.access'))->toBeTrue();
});

it('denies when billing is on but the tenant has no plan', function () {
    expect(app(EntitlementResolver::class)->allows(entitlementCompanyOnPlan(null), 'core.access'))->toBeFalse();
});

it('denies an unknown plan id without throwing', function () {
    expect(app(EntitlementResolver::class)->allows(entitlementCompanyOnPlan('ghost'), 'core.access'))->toBeFalse();
});

it('opens every feature when billing is disabled', function () {
    config(['larafoundry.billing.enabled' => false]);

    $resolver = app(EntitlementResolver::class);

    expect($resolver->allows(entitlementCompanyOnPlan(null), 'production.module'))->toBeTrue()
        ->and($resolver->allows(entitlementCompanyOnPlan('free'), 'anything.at.all'))->toBeTrue();
});

it('answers through the LaraFoundryBilling facade too', function () {
    expect(LaraFoundryBilling::allows(entitlementCompanyOnPlan('business'), 'priority.support'))->toBeTrue()
        ->and(LaraFoundryBilling::allows(entitlementCompanyOnPlan('free'), 'priority.support'))->toBeFalse();
});
