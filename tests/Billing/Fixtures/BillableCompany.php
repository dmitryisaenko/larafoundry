<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures;

use Dmitryisaenko\LaraFoundry\Billing\Concerns\BillingCustomer;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;

/**
 * Stands in for the host's own Billable Company subclass in the add-on test suite
 * (the real one lives in the host app, kohana.io, and is added at integration
 * time — out of B3). It extends the core Company and mixes in the add-on's
 * {@see BillingCustomer} trait exactly as a host would, sharing the `companies`
 * table (plus the Cashier columns the add-on migration adds), so tests can point
 * `larafoundry-billing.billable_model` at a genuinely Billable model.
 */
class BillableCompany extends Company
{
    use BillingCustomer;

    protected $table = 'companies';
}
