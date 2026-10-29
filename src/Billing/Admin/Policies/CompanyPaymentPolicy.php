<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Admin\Policies;

use Dmitryisaenko\LaraFoundry\Admin\Policies\CompanyPolicy;
use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Authorizes the super-admin payments console (sub-phase B7b).
 *
 * The console is READ-ONLY, so the only ability is viewing. The whole admin zone
 * already sits behind the `larafoundry.admin` route gate; this is the second lock at
 * the action, checked through {@see VisitorStatus} (the canonical super-admin check)
 * rather than a re-rolled flag read. Mirrors the core's
 * {@see CompanyPolicy}: a plain injected
 * class, not a Gate-registered model policy.
 */
class CompanyPaymentPolicy
{
    public function __construct(
        protected VisitorStatus $status,
    ) {}

    /**
     * Whether the actor may view the payments console.
     */
    public function viewAny(?Authenticatable $admin): bool
    {
        return $this->status->isAdmin($admin);
    }
}
