<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing;

use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableUser;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\CheckoutGateway;

/**
 * Boots the add-on the way a B2C host does: `larafoundry.tenancy.mode = personal`, with
 * the payer being the USER and no companies anywhere.
 *
 * Deliberately the same feature surface as {@see ServicePaymentTestCase} (billing on, a
 * checkout gateway, the free month, promo codes) plus the affiliate program, so the
 * personal suite proves the SAME behaviours the teams suites prove — that parallelism is
 * the point of the mode support, not a reduced subset.
 *
 * Middleware groups are narrowed to `auth` for the same testbench reason as the teams
 * suites (there is no `web` group there).
 */
abstract class PersonalTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('larafoundry.tenancy.mode', 'personal');
        $app['config']->set('larafoundry.billing.enabled', true);

        // The payer: the host's User, with the Cashier + subscription traits.
        $app['config']->set('larafoundry-billing.billable_model', BillableUser::class);
        $app['config']->set('auth.providers.users.model', BillableUser::class);

        $app['config']->set('larafoundry-billing.gateways', ['stripe' => CheckoutGateway::class]);
        $app['config']->set('larafoundry.billing.gateway.default', 'stripe');

        $app['config']->set('larafoundry-billing.routes.web.middleware', ['auth']);

        $app['config']->set('larafoundry-billing.trial.free_month.enabled', true);
        $app['config']->set('larafoundry-billing.trial.free_month.period', 'monthly');
        $app['config']->set('larafoundry-billing.promo.enabled', true);

        $app['config']->set('larafoundry-billing.affiliate.enabled', true);
        $app['config']->set('larafoundry-billing.affiliate.attribution.capture_route.middleware', []);
        $app['config']->set('larafoundry-billing.affiliate.dashboard.middleware', ['auth']);

        // Payment receipts are asserted explicitly where they matter; keep the always-on
        // listener from mailing in every accrual test.
        $app['config']->set('larafoundry-billing.notifications.enabled', false);
    }

    /**
     * Stand up the base `users` table (the core only ALTERs it) before the package
     * migrations — which, in this mode, put the billing columns on it.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
