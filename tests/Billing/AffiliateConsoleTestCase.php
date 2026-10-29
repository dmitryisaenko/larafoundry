<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing;

/**
 * Boots the affiliate super-admin console suite (Ф4.4): the B7b admin harness
 * ({@see AdminConsoleTestCase} — admin enabled, routes narrowed to auth + SubstituteBindings
 * + the super-admin gate) with the affiliate program turned on so its console routes and
 * menu register. Payment notifications stay off (the suite exercises the console, not mail).
 */
abstract class AffiliateConsoleTestCase extends AdminConsoleTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('larafoundry-billing.affiliate.enabled', true);
        $app['config']->set('larafoundry-billing.notifications.enabled', false);
    }
}
