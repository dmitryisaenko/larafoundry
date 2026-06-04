<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Support;

use Dmitryisaenko\LaraFoundry\Billing\Contracts\EntitlementResolver;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;

/**
 * The FREE core's entitlement resolver: everything is allowed (phase 3.1).
 *
 * No plans, no feature-gating in the free core — so every feature is entitled,
 * mirroring how `Company::hasAccess()` stays open while billing is disabled. The
 * seam exists so the Billing↔RBAC loop (roadmap §40) has a real binding to call;
 * the paid add-on rebinds {@see EntitlementResolver} to read the plan's features
 * and gate accordingly.
 */
class NullEntitlementResolver implements EntitlementResolver
{
    public function allows(Tenant $tenant, string $feature): bool
    {
        return true;
    }
}
