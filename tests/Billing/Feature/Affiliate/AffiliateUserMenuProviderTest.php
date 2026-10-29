<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Navigation\AffiliateUserMenuProvider;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuItem;

/**
 * The partner-dashboard menu provider (Ф4.4): one item on the 'tenant' level, shown in
 * auto/self_serve eligibility and hidden in admin (where partners reach the page by a
 * direct link).
 */
it('supports only the tenant level', function () {
    $provider = new AffiliateUserMenuProvider;

    expect($provider->supports('tenant'))->toBeTrue()
        ->and($provider->supports('admin'))->toBeFalse()
        ->and($provider->getMenuItems('admin'))->toBe([]);
});

it('adds the dashboard link in auto/self_serve eligibility', function () {
    config()->set('larafoundry-billing.affiliate.eligibility', 'auto');

    $items = (new AffiliateUserMenuProvider)->getMenuItems('tenant');

    expect($items)->toHaveCount(1)
        ->and($items[0])->toBeInstanceOf(MenuItem::class)
        ->and($items[0]->route)->toBe('affiliate.dashboard');

    config()->set('larafoundry-billing.affiliate.eligibility', 'self_serve');
    expect((new AffiliateUserMenuProvider)->getMenuItems('tenant'))->toHaveCount(1);
});

it('hides the dashboard link in admin eligibility', function () {
    config()->set('larafoundry-billing.affiliate.eligibility', 'admin');

    expect((new AffiliateUserMenuProvider)->getMenuItems('tenant'))->toBe([]);
});
