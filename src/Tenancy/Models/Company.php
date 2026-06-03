<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Models;

use Dmitryisaenko\LaraFoundry\Media\LaraFoundryMedia;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Auth\User as DefaultUser;

/**
 * The base company — the tenant in `teams` mode (phase 1.2).
 *
 * Deliberately thin (decision D1.2-a): the core owns only the columns every
 * multi-tenant SaaS needs. The host extends this model and points
 * `config('larafoundry.tenancy.company_model')` at its subclass to add business
 * columns (financial details, activity type, …) and their fillable/casts.
 *
 * Billing behaviour is a phase-3 seam: `hasAccess()` returns true here so
 * downstream gates compile now and gain real subscription logic later without
 * changing call sites (roadmap §40, decision D1.2-b).
 */
class Company extends Model implements Tenant
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /**
     * Pivot columns carried on the company_user relation.
     *
     * Single source of truth so both sides of the relation (Company::users and
     * the user's BelongsToTenancy::companies) stay in sync — phase 1.3 adds the
     * role pivot column here once, not in two places.
     *
     * @var list<string>
     */
    public const PIVOT_COLUMNS = [
        'is_owner',
        'added_by_id',
        'is_deleted',
        'removal_requested_at',
        'removal_requested_by',
        'default_route',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'description',
        'country',
        'created_by_id',
        'logo',
        'logo_path',
    ];

    /**
     * Route-bind companies by their uuid, never the numeric id.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Columns that should receive a generated uuid.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getTenantKey(): int|string
    {
        return $this->getKey();
    }

    /**
     * The founder who created the company.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo($this->userModel(), 'created_by_id');
    }

    /**
     * Members of the company (excluding soft-removed ones).
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany($this->userModel(), 'company_user')
            ->withPivot(self::PIVOT_COLUMNS)
            ->withTimestamps()
            ->wherePivot('is_deleted', false);
    }

    /**
     * Members who are owners of the company.
     */
    public function owners(): BelongsToMany
    {
        return $this->users()->wherePivot('is_owner', true);
    }

    /**
     * Pending/historical invitations for this company.
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(CompanyInvitation::class);
    }

    /**
     * Whether the company still has its setup to finish.
     *
     * In phase 1.2 (no billing) a company is "in setup" until it has at least
     * one detail beyond the name — the host refines this. Kept as a seam so the
     * creation wizard can branch on it.
     */
    public function isInSetup(): bool
    {
        return $this->country === null;
    }

    /**
     * Billing access gate — phase-3 seam.
     *
     * Always true in the free core; the billing add-on overrides this with real
     * trial/subscription checks. Call sites (gates, middleware) depend on this
     * method so they never change when billing lands.
     */
    public function hasAccess(): bool
    {
        return true;
    }

    /**
     * Public URL of the company logo, or null when none is set (phase 2.4).
     *
     * Resolved through {@see LaraFoundryMedia::logoUrl()} rather than a hardcoded
     * `Storage::disk('public')`, so the logo follows the configured media disk
     * (portable to S3). The UI falls back to a generated company mark when this
     * is null — the core does not auto-placeholder here.
     */
    public function getLogoUrlAttribute(): ?string
    {
        return LaraFoundryMedia::logoUrl($this->logo_path);
    }

    /**
     * Attach a user as a member, restoring a previously soft-removed membership.
     *
     * Checks the raw pivot (not the `users()` relation, which hides soft-removed
     * rows) so re-adding someone updates the existing row instead of colliding
     * on the unique (company_id, user_id) constraint.
     *
     * Re-adding NEVER demotes an existing owner: ownership is sticky (an owner
     * who is re-invited and accepts — `isOwner` defaults to false — must not be
     * silently stripped of ownership). The new owner flag is OR-ed with whatever
     * the row already had. This is safe against privilege-resurrection because
     * removeEmployee() clears is_owner on soft-remove, so a dormant row never
     * carries a stale owner flag for the OR to pick up.
     */
    public function addEmployee(Authenticatable $user, ?int $addedById = null, bool $isOwner = false): void
    {
        $userId = $user->getAuthIdentifier();

        $existing = $this->newPivotStatementForId($userId);
        $existingRow = $existing->first();

        $attributes = [
            'is_owner' => $isOwner || ($existingRow && $existingRow->is_owner),
            'is_deleted' => false,
            'added_by_id' => $addedById,
            'removal_requested_at' => null,
            'removal_requested_by' => null,
        ];

        if ($existingRow) {
            // Update the raw pivot row directly: the users() relation hides
            // soft-removed members (is_deleted = true), so updateExistingPivot
            // through it would never find a row to restore.
            $existing->update(array_merge($attributes, ['updated_at' => now()]));

            return;
        }

        $this->users()->attach($userId, $attributes);
    }

    /**
     * A query against the raw company_user row for the given user (any state).
     */
    protected function newPivotStatementForId(int|string $userId): Builder
    {
        return $this->getConnection()->table('company_user')
            ->where('company_id', $this->getKey())
            ->where('user_id', $userId);
    }

    /**
     * Soft-remove a member (keeps the row for audit) and let the caller settle
     * the user's active company afterwards.
     *
     * Ownership is cleared on removal: a soft-removed row must NOT keep is_owner,
     * otherwise re-inviting that person as a plain member would silently restore
     * them as an owner (addEmployee ORs the incoming flag with the dormant row).
     * Clearing here keeps owner-stickiness scoped to CURRENTLY active members.
     */
    public function removeEmployee(Authenticatable $user): void
    {
        $this->users()->updateExistingPivot($user->getAuthIdentifier(), [
            'is_deleted' => true,
            'is_owner' => false,
        ]);
    }

    /**
     * The host's authenticatable model class.
     *
     * @return class-string<Model>
     */
    protected function userModel(): string
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model', DefaultUser::class);

        return $model;
    }
}
