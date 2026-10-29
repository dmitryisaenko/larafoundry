<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Support;

use Dmitryisaenko\LaraFoundry\Billing\Contracts\EntitlementResolver;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanContract;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanRepositoryContract;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;

/**
 * The add-on's real entitlement resolver (sub-phase B6) — the Billing/RBAC loop.
 *
 * Rebinds the core's open {@see NullEntitlementResolver}.
 * Answers "does this tenant's plan pay for feature X?" by membership in the plan's
 * {@see PlanContract::features()} list,
 * the SAME slug vocabulary RBAC permissions use (phase 1.3) — so a route can be gated
 * by both "who may" (RBAC) and "which plan pays" (this).
 *
 * Decision D-B6-2: membership only. Whether the subscription is currently LIVE is a
 * separate axis (Company::hasAccess() / SubscriptionState and the B7 enforcement) that
 * this resolver deliberately does not re-check, so the two billing concerns stay
 * cleanly separated.
 *
 * Fail-closed and never throwing, exactly as the contract demands:
 *   - billing switched off => open (true), mirroring how hasAccess() stays open in the
 *     free core, so a host that installed the add-on but has not turned billing on is
 *     not locked out;
 *   - billing on, no/unknown plan => denied (false), not an error.
 */
final class PlanEntitlementResolver implements EntitlementResolver
{
    public function __construct(private readonly PlanRepositoryContract $plans) {}

    public function allows(Tenant $tenant, string $feature): bool
    {
        // Billing off: the free core stays fully open (mirror Company::hasAccess).
        if (! (bool) config('larafoundry.billing.enabled', false)) {
            return true;
        }

        $planId = method_exists($tenant, 'getAttribute')
            ? $tenant->getAttribute('plan_id')
            : null;

        // Billing on but the tenant has no plan: fail-closed, nothing is entitled.
        if (! is_string($planId) || $planId === '') {
            return false;
        }

        $plan = $this->plans->find($planId);

        // An unknown plan id is denied, never thrown on: the column could hold a
        // stale/typo'd id, and the gate must degrade closed rather than crash.
        if ($plan === null) {
            return false;
        }

        return in_array($feature, $plan->features(), true);
    }
}
