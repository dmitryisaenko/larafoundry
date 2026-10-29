<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Navigation\AffiliateAdminMenuProvider;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuItem;

/**
 * The affiliate console menu provider (Ф4.4): contributes Affiliates and Payouts to the
 * 'admin' level only, behind the zone gate (no per-item policy), after the core provider.
 */
it('supports only the admin level', function () {
    $provider = new AffiliateAdminMenuProvider;

    expect($provider->supports('admin'))->toBeTrue()
        ->and($provider->supports('tenant'))->toBeFalse()
        ->and($provider->getMenuItems('tenant'))->toBe([]);
});

it('contributes the affiliates and payouts items with admin route patterns', function () {
    $items = (new AffiliateAdminMenuProvider)->getMenuItems('admin');

    expect($items)->toHaveCount(2)
        ->and($items[0])->toBeInstanceOf(MenuItem::class);

    $byRoute = collect($items)->keyBy('route');

    expect($byRoute->has('admin.affiliates.index'))->toBeTrue()
        ->and($byRoute->has('admin.payouts.index'))->toBeTrue()
        ->and($byRoute['admin.affiliates.index']->activePatterns)->toBe(['admin.affiliates.*'])
        ->and($byRoute['admin.payouts.index']->activePatterns)->toBe(['admin.payouts.*'])
        ->and($byRoute['admin.affiliates.index']->policy)->toBeNull();
});

it('runs after the core admin provider', function () {
    expect((new AffiliateAdminMenuProvider)->priority())->toBe(10);
});
