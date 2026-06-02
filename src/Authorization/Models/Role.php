<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Authorization\Models;

use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection;

/**
 * A named bundle of permissions, optionally scoped to a company (phase 1.3).
 *
 * Four kinds, distinguished by flags (no `level` column — the hierarchy is the
 * trait's check priority, see HasRolesAndPermissions):
 *   - global   (is_global, company_id=NULL)   — applies everywhere, e.g. `authenticated`.
 *   - template (is_template, company_id=NULL)  — cloned into each new company on creation.
 *   - company  (no flags, company_id set)      — a template clone.
 *   - custom   (is_custom, company_id set)     — created by an owner in their company.
 *
 * `company()` points at the configured company model so a host subclass is honored.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_global
 * @property bool $is_template
 * @property bool $is_custom
 * @property int|null $company_id
 * @property string|null $description
 * @property int|null $created_by_id
 */
class Role extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'is_global',
        'is_template',
        'is_custom',
        'company_id',
        'description',
        'created_by_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_global' => 'boolean',
            'is_template' => 'boolean',
            'is_custom' => 'boolean',
        ];
    }

    /**
     * The company this role belongs to (NULL for global/template roles).
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo($this->companyModel());
    }

    /**
     * The user who authored this custom role.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo($this->userModel(), 'created_by_id');
    }

    /**
     * Permissions this role grants.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->withTimestamps();
    }

    /**
     * Users who hold this role (tenant-scoped via the pivot's company_id).
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany($this->userModel(), 'user_roles')
            ->withPivot('company_id', 'assigned_by_id')
            ->withTimestamps();
    }

    public function scopeGlobal($query)
    {
        return $query->where('is_global', true);
    }

    public function scopeTemplate($query)
    {
        return $query->where('is_template', true)->where('is_global', false);
    }

    public function scopeForCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeCustom($query)
    {
        return $query->where('is_custom', true);
    }

    public function isGlobal(): bool
    {
        return (bool) $this->is_global;
    }

    public function isTemplate(): bool
    {
        return (bool) $this->is_template;
    }

    public function isCustom(): bool
    {
        return $this->is_custom === true;
    }

    /**
     * A role is editable when it is neither a global nor a template role — i.e. a
     * company-scoped clone or custom role. Global/template rows are managed only
     * through the catalog config + `permissions:sync`, never the company UI.
     */
    public function isEditable(): bool
    {
        return ! $this->is_global && ! $this->is_template;
    }

    /**
     * A role can be deleted only if it is custom AND no member of its company
     * still holds it (deleting an in-use role would silently drop access).
     */
    public function canBeDeleted(): bool
    {
        if (! $this->isCustom()) {
            return false;
        }

        return $this->users()
            ->wherePivot('company_id', $this->company_id)
            ->count() === 0;
    }

    public function hasPermission(Permission|string $permission): bool
    {
        if ($permission instanceof Permission) {
            return $this->permissions->contains('id', $permission->id);
        }

        return $this->permissions->contains('slug', $permission);
    }

    /**
     * Grant permissions to this role (idempotent — keeps existing links).
     *
     * @param  Permission|string|array<int, Permission|string>  $permissions
     */
    public function givePermissionTo(Permission|array|string $permissions): self
    {
        $this->permissions()->syncWithoutDetaching($this->resolvePermissionIds($permissions));

        return $this;
    }

    /**
     * @param  Permission|string|array<int, Permission|string>  $permissions
     */
    public function revokePermissionFrom(Permission|array|string $permissions): self
    {
        $this->permissions()->detach($this->resolvePermissionIds($permissions));

        return $this;
    }

    /**
     * @param  array<int, Permission|string>  $permissions
     */
    public function syncPermissions(array $permissions): self
    {
        $this->permissions()->sync($this->resolvePermissionIds($permissions));

        return $this;
    }

    /**
     * @param  Permission|string|array<int, Permission|string>  $permissions
     * @return Collection<int, int>
     */
    protected function resolvePermissionIds(Permission|array|string $permissions)
    {
        $permissions = is_array($permissions) ? $permissions : [$permissions];

        return collect($permissions)->map(function ($permission) {
            if ($permission instanceof Permission) {
                return $permission->id;
            }

            return Permission::where('slug', $permission)->firstOrFail()->id;
        });
    }

    /**
     * @return class-string<Model>
     */
    protected function companyModel(): string
    {
        /** @var class-string<Model> $model */
        $model = config(
            'larafoundry.tenancy.company_model',
            Company::class,
        );

        return $model;
    }

    /**
     * @return class-string<Model>
     */
    protected function userModel(): string
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model', User::class);

        return $model;
    }
}
