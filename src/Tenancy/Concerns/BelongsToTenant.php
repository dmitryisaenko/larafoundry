<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Concerns;

use Dmitryisaenko\LaraFoundry\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Applies tenant isolation to a domain model (orders, products, …).
 *
 *     class Order extends Model { use BelongsToTenant; }
 *
 * Two guarantees:
 *   1. Reads are scoped — {@see TenantScope} (FAIL-CLOSED) filters every query
 *      to the active tenant; no active tenant means zero rows, never all rows.
 *   2. Writes are bound — a new record's tenant foreign key is auto-filled from
 *      the active tenant on create. The key is NOT in `$fillable` (it must never
 *      be mass-assignable from request input), and creating a row with no active
 *      tenant throws rather than persisting an orphaned, unscoped record.
 *
 * The foreign key column is config-driven (`tenancy.foreign_key`, default
 * `company_id`) in `teams` mode and `user_id` in `personal` mode, so the same
 * trait works in both without per-model changes.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            $column = TenantScope::tenantColumn();

            // Respect an explicitly-set tenant key (e.g. admin code running
            // inside withoutTenancy() that assigns it deliberately).
            if ($model->getAttribute($column) !== null) {
                return;
            }

            $tenantKey = TenantScope::currentTenantKey();

            if ($tenantKey === null) {
                throw new RuntimeException(sprintf(
                    'Cannot create [%s] without an active tenant. Set %s explicitly inside withoutTenancy() for admin/console writes.',
                    static::class,
                    $column,
                ));
            }

            $model->setAttribute($column, $tenantKey);
        });
    }

    /**
     * The owning tenant (a company in teams mode).
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(
            config('larafoundry.tenancy.company_model'),
            TenantScope::tenantColumn(),
        );
    }

    /**
     * Query a specific tenant's rows, bypassing the active-tenant scope.
     *
     * For admin/console use where the caller knows which tenant it means.
     */
    public function scopeForTenant(Builder $query, int|string $tenantKey): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class)
            ->where($this->qualifyColumn(TenantScope::tenantColumn()), $tenantKey);
    }

    /**
     * Drop tenant isolation entirely (admin, seeders, cross-tenant reports).
     *
     * Use sparingly and only in trusted code paths — this is the deliberate
     * escape hatch from fail-closed isolation.
     */
    public function scopeWithoutTenancy(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
