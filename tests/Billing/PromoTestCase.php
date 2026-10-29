<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing;

use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;

/**
 * Boots the add-on with the promo registry and the free-month offer turned on, but
 * NO gateway — the whole point of B5's app-side free month is that it works with no
 * Stripe/Paddle wiring (decision D-B5-2). The promo-validate route is enabled so the
 * endpoint tests can hit it.
 *
 * It stands up the base `users` table (companies.created_by_id is a FK to it); the
 * `companies`, `promo_codes` and `promo_code_redemptions` tables come from the core
 * and add-on migrations the providers load.
 */
abstract class PromoTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        // The billing module is dormant unless billing is on (v0.45.0).
        $app['config']->set('larafoundry.billing.enabled', true);

        $app['config']->set('larafoundry-billing.billable_model', BillableCompany::class);

        $app['config']->set('larafoundry-billing.promo.enabled', true);
        $app['config']->set('larafoundry-billing.promo.validate_route.enabled', true);

        $app['config']->set('larafoundry-billing.trial.free_month.enabled', true);
        $app['config']->set('larafoundry-billing.trial.free_month.period', 'monthly');
        $app['config']->set('larafoundry-billing.trial.free_month.length_days', 30);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
