<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Support;

use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Adapts an authenticated user into a {@see Tenant} for `personal` mode.
 *
 * The user is its own tenant; the tenant key is the user's auth identifier, so
 * tenant-scoped models filter by `user_id`. A thin value object — it holds no
 * state beyond the user reference.
 */
final class UserTenant implements Tenant
{
    public function __construct(
        public readonly Authenticatable $user,
    ) {}

    public function getTenantKey(): int|string
    {
        return $this->user->getAuthIdentifier();
    }
}
