<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing;

use Dmitryisaenko\LaraFoundry\Billing\Gateways\CashierPaddleDriver;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\PaddleBillableCompany;
use Laravel\Paddle\CashierServiceProvider;

/**
 * Boots the add-on with the Paddle driver wired, against a real Cashier-Paddle
 * Billable model — the Paddle sibling of {@see StripeTestCase}. It:
 *
 *   - registers Cashier-Paddle's provider so its `customers`/`subscriptions` tables
 *     and webhook route exist (the add-on does not ship those — Cashier owns them);
 *   - LOADS Cashier-Paddle's migrations explicitly: its provider only `publishes`
 *     them (under runningInConsole) and never `loadMigrationsFrom`, so without this
 *     the polymorphic `customers` table the mirror relies on would not exist in the
 *     test database;
 *   - declares `gateways.paddle` BEFORE boot so registerGatewayDrivers() registers
 *     the driver against the core manager;
 *   - points `paddle_billable_model` at the {@see PaddleBillableCompany} fixture, with
 *     dummy Paddle credentials so isConfigured() is true and the webhook verifier has
 *     a signing secret — no real key, no network.
 *
 * The shipped config keeps `gateways` empty; turning Paddle on is the host's opt-in
 * (env + publish), which is exactly what this test case simulates.
 */
abstract class PaddleTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            CashierServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // The billing module is dormant unless billing is on (v0.45.0).
        $app['config']->set('larafoundry.billing.enabled', true);

        $app['config']->set('larafoundry-billing.gateways', [
            'paddle' => CashierPaddleDriver::class,
        ]);

        $app['config']->set('larafoundry-billing.paddle_billable_model', PaddleBillableCompany::class);

        // Map the example plans to test Paddle price ids so the price ↔ plan reverse
        // lookup (used by the webhook mirror) has something to resolve. The shipped
        // config sources these from env (null in tests).
        $app['config']->set('larafoundry-billing.plans.definitions.pro.gateways.paddle', [
            'monthly' => 'pri_pro_monthly',
            'yearly' => 'pri_pro_yearly',
        ]);
        $app['config']->set('larafoundry-billing.plans.definitions.business.gateways.paddle', [
            'monthly' => 'pri_business_monthly',
            'yearly' => 'pri_business_yearly',
        ]);

        $app['config']->set('larafoundry-billing.paddle.api_key', 'pdl_test_dummy');
        $app['config']->set('larafoundry-billing.paddle.webhook.secret', 'pdl_whsec_test_dummy');

        // Set ONLY the add-on keys (the realistic host config). The provider bridges
        // the webhook secret into the flat `cashier.webhook_secret` Cashier-Paddle's
        // route verifies on — deliberately left empty here so the bridge is exercised.
        $app['config']->set('cashier.api_key', 'pdl_test_dummy');
        $app['config']->set('cashier.webhook_secret', null);

        // NOTE: the add-on's provider points Cashier-Paddle at its own `paddle_`-prefixed
        // model overrides (and loads their migrations) when gateways.paddle is set — which
        // it is above. So `Cashier::useCustomerModel` and the Paddle table migrations are
        // wired by the provider, not here; the mirror's findBillable() resolves against
        // `paddle_customers`.
    }

    /**
     * Stand up the base `users` table the core only ALTERs; the Paddle tables
     * (`paddle_customers` etc.) are loaded by the provider's registerPaddleModels()
     * since gateways.paddle is configured above.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
