<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Admin\Policies;

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Authorizes super-admin company management (phase 3.3).
 *
 * The whole admin zone already sits behind the `larafoundry.admin` route gate,
 * so viewing the list is covered by the route. This policy is the second lock on
 * the DESTRUCTIVE action — blocking a company takes its entire team offline — so
 * the authority is checked at the action, not only at the route, and through
 * {@see VisitorStatus} (the canonical super-admin check) rather than a re-rolled
 * flag read. Mirrors {@see ImpersonationPolicy}: a plain injected class, not a
 * Gate-registered model policy, because the subject (block/unblock) is an
 * operation, not an ability tied to a single model instance.
 *
 * There is no "cannot block your own company" carve-out: a super-admin operates
 * the platform, not a tenant, so the self-company case does not arise.
 */
class CompanyPolicy
{
    public function __construct(
        protected VisitorStatus $status,
    ) {}

    /**
     * Whether the actor may block or unblock a company.
     */
    public function block(Authenticatable $admin): bool
    {
        return $this->status->isAdmin($admin);
    }
}
