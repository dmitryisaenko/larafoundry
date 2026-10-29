<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing;

use Dmitryisaenko\LaraFoundry\Billing\Gateways\CashierPaddleDriver;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\PaddleBillableCompany;
use Laravel\Paddle\CashierServiceProvider;

/**
 * Boots the Paddle driver as configured (api key + Billable model) but with NO
 * webhook signing secret — the misconfiguration the mirror must fail closed on.
 * Used to prove the webhook mirror listener is NOT registered without a signing
 * secret, so there is no unauthenticated access-mutation path. Paddle sibling of
 * {@see StripeNoWebhookSecretTestCase}.
 */
abstract class PaddleNoWebhookSecretTestCase extends TestCase
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
        $app['config']->set('larafoundry-billing.paddle.api_key', 'pdl_test_dummy');

        // Deliberately NO webhook secret anywhere (both the add-on key and the flat
        // Cashier-Paddle key).
        $app['config']->set('larafoundry-billing.paddle.webhook.secret', null);
        $app['config']->set('cashier.api_key', 'pdl_test_dummy');
        $app['config']->set('cashier.webhook_secret', null);
    }
}
