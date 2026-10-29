<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Dashboard\BillingRevenueWidgetProvider;
use Dmitryisaenko\LaraFoundry\Billing\Dashboard\RevenueMetricsService;
use Dmitryisaenko\LaraFoundry\Dashboard\Support\DashboardBuilder;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The add-on's revenue dashboard widget (B9): the provider contributes one
 * `billing.revenue` widget on the admin level, and the boot wiring registers it
 * onto the core DashboardBuilder when billing + the admin console are on. The
 * billing-OFF gate is proven separately (ServiceProviderSmokeTest, billing off).
 */
function widgetAdmin(): AdminUser
{
    return AdminUser::query()->create([
        'name' => 'Root',
        'email' => 'root@example.test',
        'password' => bcrypt('secret'),
    ]);
}

it('contributes a single revenue widget on the admin level only', function () {
    $provider = app(BillingRevenueWidgetProvider::class);
    $admin = widgetAdmin();

    $widgets = $provider->getWidgets('admin', $admin);

    expect($widgets)->toHaveCount(1)
        ->and($widgets[0]->key)->toBe('billing.revenue')
        ->and($widgets[0]->component)->toBe('RevenueWidget')
        ->and($widgets[0]->titleKey)->toBe('Revenue')
        ->and($widgets[0]->order)->toBe(7)
        // No per-item policy: the larafoundry.admin zone is the authority.
        ->and($widgets[0]->policy)->toBeNull();

    // Nothing on the tenant level, and it runs after the core provider.
    expect($provider->supports('admin'))->toBeTrue()
        ->and($provider->supports('tenant'))->toBeFalse()
        ->and($provider->getWidgets('tenant', $admin))->toBe([])
        ->and($provider->priority())->toBe(10);
});

it('wires the revenue widget into the core dashboard when billing and admin are on', function () {
    $admin = widgetAdmin();

    $widgets = app(DashboardBuilder::class)->build('admin', $admin);
    $keys = array_column($widgets, 'key');

    expect($keys)->toContain('billing.revenue')
        // order 7 places it before the core widgets (users 10, companies 20, activity 30).
        ->and($keys[0])->toBe('billing.revenue');

    $revenue = collect($widgets)->firstWhere('key', 'billing.revenue');

    expect($revenue['component'])->toBe('RevenueWidget')
        ->and($revenue['order'])->toBe(7)
        ->and($revenue['data'])->toHaveKeys(['currencies', 'active_subscriptions']);
});

it('ships the title as an i18n key, not a translated string', function () {
    $widget = app(BillingRevenueWidgetProvider::class)->getWidgets('admin', widgetAdmin())[0];

    expect($widget->toArray()['titleKey'])->toBe('Revenue');
});

it('resolves the metrics service without constructor wiring', function () {
    expect(app(RevenueMetricsService::class))->toBeInstanceOf(RevenueMetricsService::class);
});
