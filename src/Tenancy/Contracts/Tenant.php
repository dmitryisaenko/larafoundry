<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Contracts;

/**
 * A tenant — the thing data is isolated by.
 *
 * In `teams` mode the tenant is a Company; in `personal` mode it is the User
 * itself. Both implement this contract so the resolver, scope and middleware can
 * treat "the current tenant" uniformly without caring which mode is active.
 *
 * `getTenantKey()` returns the value that lands in a domain model's tenant
 * foreign key (a company id, or a user id in personal mode).
 */
interface Tenant
{
    /**
     * The identifier stored in a tenant-scoped model's foreign key column.
     */
    public function getTenantKey(): int|string;
}
