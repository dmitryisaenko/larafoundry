<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing;

use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\CheckoutGateway;

/**
 * Boots the owner billing-UI suite (sub-phase B7a): the payments harness plus a
 * checkout gateway, the free-month offer and promo on, so the controller flow runs end
 * to end without Stripe.
 *
 * The gateway is registered (and made the default) BEFORE boot so the provider's
 * extend() wiring picks it up; the web routes' middleware is narrowed to `auth` because
 * testbench has no `web` group and the tests assert redirects / Inertia props, not
 * session/CSRF.
 */
abstract class ServicePaymentTestCase extends PaymentsTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('larafoundry-billing.gateways', ['stripe' => CheckoutGateway::class]);
        $app['config']->set('larafoundry.billing.gateway.default', 'stripe');

        $app['config']->set('larafoundry-billing.routes.web.middleware', ['auth']);

        $app['config']->set('larafoundry-billing.trial.free_month.enabled', true);
        $app['config']->set('larafoundry-billing.trial.free_month.period', 'monthly');
        $app['config']->set('larafoundry-billing.promo.enabled', true);
    }
}
