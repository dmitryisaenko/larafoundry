<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing;

use Illuminate\Routing\Middleware\SubstituteBindings;

/**
 * Boots the super-admin console suite (sub-phase B7b): the billing-on payments harness
 * with the admin routes/menu loaded.
 *
 * The console route group is narrowed to `auth` + the core super-admin gate
 * (`larafoundry.admin`) because testbench ships no `web` middleware group (same reason
 * {@see ServicePaymentTestCase} narrows the owner routes); the `web`/`verified` layers are
 * exercised in the host integration test, not here. The acting super-admin is an
 * {@see Fixtures\AdminUser}.
 */
abstract class AdminConsoleTestCase extends PaymentsTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('larafoundry-billing.admin.enabled', true);
        // SubstituteBindings stands in for the `web` group (absent in testbench) so the
        // {promo_code} route-model binding resolves; auth + the super-admin gate are the
        // axes under test.
        $app['config']->set('larafoundry-billing.admin.middleware', [
            'auth',
            SubstituteBindings::class,
            'larafoundry.admin',
        ]);
    }
}
