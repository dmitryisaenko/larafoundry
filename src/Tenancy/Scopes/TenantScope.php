<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Scopes;

use Dmitryisaenko\LaraFoundry\Tenancy\Concerns\BelongsToTenant;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\TenantResolver;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The global scope behind {@see BelongsToTenant}.
 *
 * FAIL-CLOSED is the whole point (decision T5): if there is no authenticated
 * user, or no active tenant can be resolved, the scope constrains the query to
 * return NOTHING (`0 = 1`) rather than silently returning every row. The legacy
 * donor did the opposite — `if ($companyId) { ... }` left queries unfiltered
 * when no company was active, which is a cross-tenant data leak. Here, the
 * default is deny.
 *
 * It is a named class (not a closure) so callers can lift it explicitly with
 * `Model::withoutGlobalScope(TenantScope::class)` for admin/console work, which
 * the trait wraps as `withoutTenancy()` / `forTenant()`.
 *
 * Mode-aware: in `teams` it filters by the configured foreign key (default
 * `company_id`); in `personal` it filters by `user_id`.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $column = $model->qualifyColumn(static::tenantColumn());
        $tenantKey = static::currentTenantKey();

        if ($tenantKey === null) {
            // No resolvable tenant → deny everything. Never leave unfiltered.
            $builder->whereRaw('0 = 1');

            return;
        }

        $builder->where($column, $tenantKey);
    }

    /**
     * The tenant key for the active tenant, or null when none resolves.
     *
     * Shared with the trait's create-time auto-fill so scope and insert always
     * agree on "who the current tenant is".
     */
    public static function currentTenantKey(): int|string|null
    {
        $user = app(AuthFactory::class)->guard()->user();

        if ($user === null) {
            return null;
        }

        $tenant = app(TenantResolver::class)->current($user);

        return $tenant?->getTenantKey();
    }

    /**
     * The column the tenant key lives in for the active mode.
     */
    public static function tenantColumn(): string
    {
        if (config('larafoundry.tenancy.mode') === 'personal') {
            return 'user_id';
        }

        return (string) config('larafoundry.tenancy.foreign_key', 'company_id');
    }
}
