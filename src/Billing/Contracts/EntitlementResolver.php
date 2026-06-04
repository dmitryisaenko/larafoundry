<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Contracts;

use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;

/**
 * Feature-gating by plan — the Billing↔RBAC loop (phase 3.1, roadmap §40).
 *
 * Answers "does this tenant's plan entitle it to feature X?" using the SAME slug
 * vocabulary as RBAC permissions (phase 1.3), so a feature can be both
 * permission-gated (who) and entitlement-gated (which plan). RBAC says the user
 * is allowed; entitlement says the subscription pays for it.
 *
 * The FREE default — {@see
 * \Dmitryisaenko\LaraFoundry\Billing\Support\NullEntitlementResolver} — returns
 * true for everything (no plan means no restriction in the free core, mirroring
 * how `hasAccess()` stays open when billing is disabled). The paid add-on rebinds
 * this to read the plan's {@see PlanContract::features()} and gate accordingly.
 */
interface EntitlementResolver
{
    /**
     * Whether the tenant is entitled to the given feature slug.
     *
     * Open by default in the free core (returns true); the add-on makes it real.
     * Implementations should fail-closed (deny) only when billing is enabled AND
     * the plan genuinely lacks the feature — never throw on an unknown plan.
     */
    public function allows(Tenant $tenant, string $feature): bool;
}
