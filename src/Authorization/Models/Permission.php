<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Authorization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User;

/**
 * An atomic, grantable permission (phase 1.3).
 *
 * Identified by a globally-unique `slug` in dot notation (`module.action`).
 * Permissions are seeded from the catalog config by `larafoundry:permissions:sync`
 * and never created at runtime — the management UI assigns existing permissions to
 * roles/users, it does not invent new slugs.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $module
 * @property string|null $description
 */
class Permission extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'module',
        'description',
    ];

    /**
     * Roles that grant this permission.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')
            ->withTimestamps();
    }

    /**
     * Users who hold this permission directly (individual grant/revoke).
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany($this->userModel(), 'user_permissions')
            ->withPivot('company_id', 'is_revoked', 'granted_by_id')
            ->withTimestamps();
    }

    public function scopeForModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    /**
     * Filter by a wildcard slug pattern (e.g. `orders.*`) at the SQL level.
     */
    public function scopeByPattern($query, string $pattern)
    {
        return $query->where('slug', 'like', str_replace('*', '%', $pattern));
    }

    public static function findBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }

    /**
     * Whether this permission's slug matches a wildcard pattern (`orders.*`).
     *
     * The pattern is regex-escaped first, so only `*` is meta — a literal dot in a
     * slug can never be abused as a regex wildcard.
     */
    public function matches(string $pattern): bool
    {
        $regex = '/^'.str_replace('\\*', '.*', preg_quote($pattern, '/')).'$/';

        return (bool) preg_match($regex, $this->slug);
    }

    /**
     * The host's authenticatable model class.
     *
     * @return class-string<Model>
     */
    protected function userModel(): string
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model', User::class);

        return $model;
    }
}
