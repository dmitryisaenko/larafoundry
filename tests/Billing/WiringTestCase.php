<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing;

use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\StubGateway;

/**
 * A test case that declares a gateway driver in config BEFORE the add-on boots,
 * so the provider's registerGatewayDrivers() registers it against the core
 * manager. This is how B1 proves the extend() seam end to end without shipping a
 * real (or fake) gateway: the stub lives in tests/ and never ships.
 *
 * Config set here survives the provider's mergeConfigFrom, which only fills keys
 * the config does not already have.
 */
abstract class WiringTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        // The billing module is dormant unless billing is on (v0.45.0).
        $app['config']->set('larafoundry.billing.enabled', true);

        $app['config']->set('larafoundry-billing.gateways', [
            'stub' => StubGateway::class,
        ]);
    }
}
