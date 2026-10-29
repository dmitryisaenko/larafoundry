<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\BillingServiceProvider;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\EntitlementResolver;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanRepositoryContract;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\RegionContext;
use Dmitryisaenko\LaraFoundry\Billing\Dashboard\BillingRevenueWidgetProvider;
use Dmitryisaenko\LaraFoundry\Billing\LaraFoundryBilling;
use Dmitryisaenko\LaraFoundry\Billing\Support\ArrayPlanRepository;
use Dmitryisaenko\LaraFoundry\Billing\Support\DefaultRegionContext;
use Dmitryisaenko\LaraFoundry\Billing\Support\NullEntitlementResolver;
use Dmitryisaenko\LaraFoundry\Billing\Support\NullPaymentGateway;
use Dmitryisaenko\LaraFoundry\Billing\Support\PaymentGatewayManager;
use Dmitryisaenko\LaraFoundry\Dashboard\Support\DashboardBuilder;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuBuilder;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Cashier as StripeCashier;
use Laravel\Paddle\Cashier as PaddleCashier;

it('boots the billing module with the core provider alone', function () {
    expect($this->app->getProviders(BillingServiceProvider::class))->not->toBeEmpty();
});

it('loads the billing config separate from the core billing toggle', function () {
    expect(config('larafoundry-billing.billable_model'))->toBe(Company::class)
        ->and(config('larafoundry-billing.gateways'))->toBe([])
        ->and(config('larafoundry-billing.license'))->toBeNull();
});

it('leaves the gateway manager on the core null driver when no gateway is configured', function () {
    $manager = app(PaymentGatewayManager::class);

    expect($manager)->toBeInstanceOf(PaymentGatewayManager::class)
        ->and($manager->driver())->toBeInstanceOf(NullPaymentGateway::class)
        ->and($manager->isConfigured())->toBeFalse()
        ->and($manager->availableDrivers())->toContain('null');
});

it('keeps billing switched off until the host opts in', function () {
    expect(LaraFoundryBilling::enabled())->toBeFalse();
});

it('keeps the seam on its empty defaults while billing is off', function () {
    expect(app(PlanRepositoryContract::class))->toBeInstanceOf(ArrayPlanRepository::class)
        ->and(app(RegionContext::class))->toBeInstanceOf(DefaultRegionContext::class)
        ->and(app(EntitlementResolver::class))->toBeInstanceOf(NullEntitlementResolver::class);
});

it('registers no billing route while billing is off', function () {
    $billing = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => (string) $route->getName())
        ->filter(fn (string $name) => preg_match('/^(billing\.|admin\.(payments|promo-codes|affiliates|payouts)\.|affiliate\.|larafoundry-billing\.|cashier\.)/', $name) === 1);

    $cashierPaths = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->uri())
        ->filter(fn (string $uri) => str_starts_with($uri, 'stripe/') || str_starts_with($uri, 'paddle/'));

    expect($billing->values()->all())->toBe([])
        ->and($cashierPaths->values()->all())->toBe([])
        ->and(StripeCashier::$registersRoutes)->toBeFalse()
        ->and(PaddleCashier::$registersRoutes)->toBeFalse();
});

it('adds no billing menu item while billing is off', function () {
    $routes = collect(app(MenuBuilder::class)->build('admin'))
        ->pluck('route')
        ->all();

    expect($routes)->not->toContain('admin.payments.index')
        ->and($routes)->not->toContain('admin.promo-codes.index')
        ->and($routes)->not->toContain('admin.affiliates.index')
        ->and($routes)->not->toContain('admin.payouts.index');
});

it('does not wire the revenue dashboard widget while billing is off', function () {
    $builder = app(DashboardBuilder::class);
    $providers = (function () {
        return $this->providers;
    })->call($builder);

    $classes = array_map('get_class', $providers);

    expect($classes)->not->toContain(BillingRevenueWidgetProvider::class);
});
