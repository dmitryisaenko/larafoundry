<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Navigation\BillingAdminMenuProvider;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuItem;

/**
 * The add-on's super-admin menu provider (B7b): contributes the Payments and Promo-codes
 * items to the 'admin' level only, behind the zone gate (no per-item policy), after the
 * core provider.
 */
it('supports only the admin level', function () {
    $provider = new BillingAdminMenuProvider;

    expect($provider->supports('admin'))->toBeTrue()
        ->and($provider->supports('tenant'))->toBeFalse()
        ->and($provider->getMenuItems('tenant'))->toBe([]);
});

it('contributes the payments and promo items with admin route patterns', function () {
    $items = (new BillingAdminMenuProvider)->getMenuItems('admin');

    expect($items)->toHaveCount(2)
        ->and($items[0])->toBeInstanceOf(MenuItem::class);

    $byRoute = collect($items)->keyBy('route');

    expect($byRoute->has('admin.payments.index'))->toBeTrue()
        ->and($byRoute->has('admin.promo-codes.index'))->toBeTrue()
        ->and($byRoute['admin.payments.index']->activePatterns)->toBe(['admin.payments.*'])
        ->and($byRoute['admin.promo-codes.index']->activePatterns)->toBe(['admin.promo-codes.*'])
        // No per-item policy: the larafoundry.admin zone is the authority.
        ->and($byRoute['admin.payments.index']->policy)->toBeNull();
});

it('runs after the core admin provider', function () {
    expect((new BillingAdminMenuProvider)->priority())->toBe(10);
});
