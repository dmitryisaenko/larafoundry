<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing;

use Dmitryisaenko\LaraFoundry\Billing\Contracts\EntitlementResolver;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PaymentGatewayInterface;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanContract;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanRepositoryContract;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\RegionContext;
use Dmitryisaenko\LaraFoundry\Billing\Support\PaymentGatewayManager;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;

/**
 * One entry point for the billing seam (phase 3.1) — mirrors LaraFoundryMedia.
 *
 * Resolves everything through the container-bound contracts, so a host or the
 * paid add-on swapping any implementation changes every call site at once. Call
 * sites use this rather than reaching for the manager / resolvers directly, and
 * never depend on whether a real gateway is installed (in the free core they all
 * resolve to the null/default implementations).
 */
class LaraFoundryBilling
{
    /**
     * Whether billing is switched on. When false the free core stays fully open
     * (see Company::hasAccess) and no gateway is expected to take money.
     */
    public static function enabled(): bool
    {
        return (bool) config('larafoundry.billing.enabled', false);
    }

    /**
     * The active payment gateway for the given tenant.
     *
     * Region routing first (a host may send a tenant to a local PSP), then the
     * configured default; with no tenant, just the default. In the free core this
     * is always the null driver — call sites check $gateway->isConfigured().
     */
    public static function gateway(?Tenant $tenant = null): PaymentGatewayInterface
    {
        $manager = app(PaymentGatewayManager::class);

        $driver = $tenant !== null
            ? app(RegionContext::class)->gatewayFor($tenant)
            : null;

        return $manager->driver($driver);
    }

    /**
     * The region context (country/currency/gateway routing).
     */
    public static function region(): RegionContext
    {
        return app(RegionContext::class);
    }

    /**
     * The plan a tenant is on, or null when it has none / billing knows no plans.
     *
     * Reads `plan_id` off the tenant model and looks it up through the bound
     * repository (empty in the free core). Fail-closed: an unknown id is null, not
     * an error.
     */
    public static function planFor(Tenant $tenant): ?PlanContract
    {
        $planId = method_exists($tenant, 'getAttribute')
            ? $tenant->getAttribute('plan_id')
            : null;

        if (! is_string($planId) || $planId === '') {
            return null;
        }

        return app(PlanRepositoryContract::class)->find($planId);
    }

    /**
     * Whether the tenant's plan entitles it to the given feature slug. Open in the
     * free core; the add-on makes it real (the Billing↔RBAC loop).
     */
    public static function allows(Tenant $tenant, string $feature): bool
    {
        return app(EntitlementResolver::class)->allows($tenant, $feature);
    }
}
