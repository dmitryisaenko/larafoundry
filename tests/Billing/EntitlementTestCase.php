<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing;

use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;

/**
 * Boots the add-on with billing turned ON and the shipped example plans
 * (free/pro/business), but NO gateway — entitlement gating is a pure read of a plan's
 * features and needs no Stripe/Paddle wiring (sub-phase B6).
 *
 * `billable_model` points at the BillableCompany fixture so a tenant is a real Eloquent
 * model whose `plan_id` the resolver can read; individual tests set the plan via
 * forceFill without touching the database.
 */
abstract class EntitlementTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('larafoundry.billing.enabled', true);
        $app['config']->set('larafoundry-billing.billable_model', BillableCompany::class);
    }
}
