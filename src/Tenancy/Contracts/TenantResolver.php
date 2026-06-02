<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resolves "which tenant is active for this request".
 *
 * This is the seam (roadmap §5) that keeps tenancy storage swappable: today the
 * `teams` resolver reads the active company from `user_sessions`; phase 6 will
 * add a token-based resolver for the mobile API. All callers depend on THIS
 * contract, never on the session directly, so that swap touches one binding.
 *
 * Implementations are bound mode-aware in the service provider: SessionTenant-
 * Resolver for `teams`, PersonalTenantResolver for `personal`.
 */
interface TenantResolver
{
    /**
     * The tenant currently active for the given user, or null when none is set
     * (no company selected yet, or the user belongs to none).
     *
     * Returning null is what drives the scope's FAIL-CLOSED behaviour: no tenant
     * resolved means tenant-scoped queries return nothing, not everything.
     */
    public function current(Authenticatable $user): ?Tenant;

    /**
     * Make the given tenant (or tenant key) the active one for this user.
     *
     * Passing null clears the active tenant.
     */
    public function setCurrent(Authenticatable $user, Tenant|int|string|null $tenant): void;

    /**
     * Clear the active tenant for this user.
     */
    public function forget(Authenticatable $user): void;
}
