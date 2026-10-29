<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing;

use Dmitryisaenko\LaraFoundry\Billing\Gateways\CashierPaddleDriver;
use Dmitryisaenko\LaraFoundry\Billing\Gateways\CashierStripeDriver;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\PaddleBillableCompany;
use Laravel\Cashier\CashierServiceProvider as StripeCashierServiceProvider;
use Laravel\Paddle\CashierServiceProvider as PaddleCashierServiceProvider;

/**
 * Boots BOTH gateways at once — the configuration a host uses when it routes some
 * regions to Stripe and others to Paddle (the reason B2 built region.gateways). It
 * registers both Cashier providers, wires both drivers in `gateways`, and points
 * each gateway's billable model at its own Company subclass (decision D-B4-1: the
 * two Billable traits collide, so each gateway gets a distinct subclass over the
 * same `companies` table). Proves Option (a)'s coexistence claim holds at runtime.
 */
abstract class MultiGatewayTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            StripeCashierServiceProvider::class,
            PaddleCashierServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // The billing module is dormant unless billing is on (v0.45.0).
        $app['config']->set('larafoundry.billing.enabled', true);

        $app['config']->set('larafoundry-billing.gateways', [
            'stripe' => CashierStripeDriver::class,
            'paddle' => CashierPaddleDriver::class,
        ]);

        // Each gateway resolves its own Billable subclass.
        $app['config']->set('larafoundry-billing.billable_model', BillableCompany::class);
        $app['config']->set('larafoundry-billing.paddle_billable_model', PaddleBillableCompany::class);

        $app['config']->set('larafoundry-billing.stripe.secret', 'sk_test_dummy');
        $app['config']->set('larafoundry-billing.stripe.webhook.secret', 'whsec_test_dummy');
        $app['config']->set('cashier.secret', 'sk_test_dummy');

        $app['config']->set('larafoundry-billing.paddle.api_key', 'pdl_test_dummy');
        $app['config']->set('larafoundry-billing.paddle.webhook.secret', 'pdl_whsec_test_dummy');
        $app['config']->set('cashier.api_key', 'pdl_test_dummy');
        $app['config']->set('cashier.webhook_secret', 'pdl_whsec_test_dummy');

        // Region routing: UA -> Paddle (MoR), everyone else -> the default gateway.
        $app['config']->set('larafoundry-billing.region.gateways', ['UA' => 'paddle']);
    }

    /**
     * Stand up both gateways' tables side by side, the way a host running Stripe AND
     * Paddle would: the base `users` table, Cashier-Stripe's own migrations (its
     * `subscriptions` / `subscription_items`), and — via the add-on provider, since
     * gateways.paddle is set above — the add-on's `paddle_`-prefixed Paddle tables.
     * Proves the two no longer collide (see TableCoexistenceTest).
     *
     * Cashier-Stripe's provider, like Paddle's, only publishes its migrations, so they
     * are loaded explicitly. Path resolved from the tests dir (Testbench base_path()
     * points at the skeleton, not the package).
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/vendor/laravel/cashier/database/migrations');
    }
}
