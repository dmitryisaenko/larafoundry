<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Events;

use Dmitryisaenko\LaraFoundry\Billing\Actions\ActivateFreeMonthAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a tenant's trial / free month is activated app-side (sub-phase B5).
 *
 * The add-on's analogue of the donor's `CompanyTrialActivated`. Dispatched by
 * {@see ActivateFreeMonthAction} after the
 * trial columns are written — a host listens to send a welcome mail, schedule the
 * trial-ending reminder, etc. The add-on itself does not enforce anything off it.
 *
 * `$source` records what started the trial (e.g. a promo `code`, or null for a
 * plain free-month grant) for analytics/audit.
 */
class TrialStarted
{
    use Dispatchable;

    public function __construct(
        public readonly Tenant $company,
        public readonly ?string $source = null,
    ) {}
}
