<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Admin\Policies;

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Authorizes the super-admin affiliate / payout console (Ф4.4).
 *
 * The whole console sits behind the `larafoundry.admin` route gate; this is the second
 * lock at the action, checked through {@see VisitorStatus} (the canonical super-admin
 * check). A plain injected class, mirroring {@see CompanyPaymentPolicy}. `viewAny` reads
 * the report; `manage` is the gate on the mutating payout actions (approve / mark-paid /
 * void) and affiliate edits.
 */
class AffiliatePolicy
{
    public function __construct(
        protected VisitorStatus $status,
    ) {}

    public function viewAny(?Authenticatable $admin): bool
    {
        return $this->status->isAdmin($admin);
    }

    public function manage(?Authenticatable $admin): bool
    {
        return $this->status->isAdmin($admin);
    }
}
