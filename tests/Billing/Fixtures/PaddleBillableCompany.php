<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures;

use Dmitryisaenko\LaraFoundry\Billing\Concerns\BillingCustomerPaddle;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;

/**
 * Stands in for the host's SECOND (Paddle-payable) Company subclass in the add-on
 * suite (decision D-B4-1). It extends the core Company and mixes in the add-on's
 * {@see BillingCustomerPaddle} trait exactly as a host would, sharing the
 * `companies` table.
 *
 * Unlike {@see BillableCompany} (Stripe), this carries NO gateway id column of its
 * own: Cashier-Paddle keeps the `paddle_id` in its own polymorphic `customers`
 * table (morphed to the billable), not on the model's table — so tests link a
 * tenant to Paddle by inserting a `customers` row, not by setting a column here.
 */
class PaddleBillableCompany extends Company
{
    use BillingCustomerPaddle;

    protected $table = 'companies';
}
