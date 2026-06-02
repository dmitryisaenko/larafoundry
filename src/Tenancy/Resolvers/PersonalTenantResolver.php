<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Resolvers;

use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\TenantResolver;
use Dmitryisaenko\LaraFoundry\Tenancy\Support\UserTenant;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * `personal`-mode resolver: the tenant is always the user itself.
 *
 * There are no companies, no selection and nothing to store — the current
 * tenant is whoever is authenticated, wrapped as a UserTenant so its key is the
 * user id. Tenant-scoped models therefore filter by `user_id`. `setCurrent` /
 * `forget` are no-ops: you can't switch away from yourself.
 */
class PersonalTenantResolver implements TenantResolver
{
    public function current(Authenticatable $user): ?Tenant
    {
        return new UserTenant($user);
    }

    public function setCurrent(Authenticatable $user, Tenant|int|string|null $tenant): void
    {
        // No-op: the tenant in personal mode is fixed to the user.
    }

    public function forget(Authenticatable $user): void
    {
        // No-op.
    }
}
