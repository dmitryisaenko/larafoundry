<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing;

use Dmitryisaenko\LaraFoundry\Billing\Gateways\CashierStripeDriver;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Laravel\Cashier\CashierServiceProvider;

/**
 * Boots the Stripe driver as configured (api secret + Billable model) but with NO
 * webhook signing secret — the misconfiguration the mirror must fail closed on.
 * Used to prove the webhook mirror listener is NOT registered without a signing
 * secret, so there is no unauthenticated access-mutation path.
 */
abstract class StripeNoWebhookSecretTestCase extends TestCase
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
            'stripe' => CashierStripeDriver::class,
        ]);
        $app['config']->set('larafoundry-billing.billable_model', BillableCompany::class);
        $app['config']->set('larafoundry-billing.stripe.secret', 'sk_test_dummy');

        // Deliberately NO webhook secret anywhere.
        $app['config']->set('larafoundry-billing.stripe.webhook.secret', null);
        $app['config']->set('cashier.secret', 'sk_test_dummy');
        $app['config']->set('cashier.webhook.secret', null);
    }
}
