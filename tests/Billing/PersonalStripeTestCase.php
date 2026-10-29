<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing;

use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableUser;
use Laravel\Cashier\Cashier;

/**
 * The Stripe harness with a PERSON as the payer: everything {@see StripeTestCase} wires
 * (Cashier's provider, the real driver, dummy secrets), but in `personal` tenancy mode
 * and with the billable pointed at the User fixture.
 *
 * This is what proves the money path end to end in the mode: a verified webhook has to
 * mirror subscription state onto the `users` row and record a ledger line keyed to the
 * user, exactly as it does onto `companies` for a team.
 */
abstract class PersonalStripeTestCase extends StripeTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('larafoundry.tenancy.mode', 'personal');
        $app['config']->set('larafoundry.billing.enabled', true);
        $app['config']->set('larafoundry-billing.billable_model', BillableUser::class);
        $app['config']->set('auth.providers.users.model', BillableUser::class);

        // Cashier::findBillable() (the webhook mirror's entry point) resolves by
        // stripe_id against its configured customer model — the person here.
        Cashier::useCustomerModel(BillableUser::class);
    }
}
