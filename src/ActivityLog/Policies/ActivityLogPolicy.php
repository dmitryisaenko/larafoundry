<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\ActivityLog\Policies;

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Authorises reading the platform activity log (phase 2.1).
 *
 * The log is a super-admin tool, so every ability collapses to "is this a
 * super-admin?" decided by the core's {@see VisitorStatus}. In v1 this mirrors
 * the route gate (`larafoundry.admin`); it exists as a policy so finer-grained
 * operator roles can refine it later without changing call sites.
 */
class ActivityLogPolicy
{
    public function __construct(
        protected VisitorStatus $visitorStatus,
    ) {}

    public function viewAny(Authenticatable $user): bool
    {
        return $this->visitorStatus->isAdmin($user);
    }

    public function view(Authenticatable $user): bool
    {
        return $this->visitorStatus->isAdmin($user);
    }
}
