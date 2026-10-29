<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures;

use Dmitryisaenko\LaraFoundry\Billing\Concerns\BillingCustomer;
use Dmitryisaenko\LaraFoundry\Billing\Concerns\BillingSubscriber;

/**
 * Stands in for the host's own payer User in `personal` tenancy mode.
 *
 * The real one lives in the host app; this mirrors exactly what the README asks a
 * B2C host to write — the Cashier identity trait plus the subscription-state trait —
 * on the same `users` table the add-on migrations extend when `billable_model` points
 * here. `getActiveCompany()` is inherited and stays null, which is the whole point:
 * in `personal` mode nothing may depend on an active company existing.
 */
class BillableUser extends PaymentUser
{
    use BillingCustomer;
    use BillingSubscriber;

    protected $table = 'users';
}
