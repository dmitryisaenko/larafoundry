<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing;

use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\PaymentUser;

/**
 * Boots the add-on with billing turned ON and the BillableCompany fixture as the
 * tenant, for the B7a payment-log (and enforcement) suites.
 *
 * It stands up the base `users` table (companies.created_by_id is a FK to it); the
 * `companies`, `promo_codes` and `company_payments` tables come from the core and
 * add-on migrations the providers load.
 */
abstract class PaymentsTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('larafoundry.billing.enabled', true);
        $app['config']->set('larafoundry-billing.billable_model', BillableCompany::class);

        // A Notifiable user model so Company::owners()/createdBy() resolve a real
        // recipient for the payment-notification tests.
        $app['config']->set('auth.providers.users.model', PaymentUser::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
