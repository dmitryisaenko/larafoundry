<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\ActivityLog\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * The core's activity-log model (phase 2.1).
 *
 * Extends spatie's Activity with the HTTP/device/geo context columns the core
 * records. Bound into `activitylog.activity_model` by the service provider so
 * spatie's own helpers (the `activity()` helper, model events from the
 * LogsActivity trait) write THESE columns too — the donor forgot this binding,
 * so its custom columns were silently dropped by the package internals.
 *
 * @property string|null $user_ip
 * @property string|null $user_agent
 * @property string|null $route_name
 * @property string|null $request_method
 * @property string|null $full_url
 * @property int|null $response_code
 * @property bool $is_successful
 * @property array<int, string>|null $user_email
 * @property string|null $geo_country
 * @property string|null $geo_city
 * @property Carbon|null $geo_updated_at
 */
class Activity extends SpatieActivity
{
    /**
     * Cast the core's extra columns. `properties` and the spatie timestamps are
     * already cast by the parent.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'user_email' => 'array',
            'is_successful' => 'boolean',
            'response_code' => 'integer',
            'geo_updated_at' => 'datetime',
        ]);
    }

    /**
     * The acting user (causer), resolved against the configured auth model.
     *
     * A convenience relation for the admin viewer so it can eager-load the actor
     * without going through the polymorphic `causer` morph. The eager-loaded row
     * is only meaningful when `causer_type` is the user morph; for a non-user
     * causer (a host may log domain events whose actor is some other
     * Authenticatable), read it through {@see self::getUserAttribute()}, which
     * returns null rather than the unrelated user that happens to share the id.
     * For a causer of any type, use spatie's polymorphic `causer()` relation.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo($this->resolveUserModel(), 'causer_id');
    }

    /**
     * Morph-safe accessor over the {@see self::user()} relation: null unless the
     * causer is actually a user, so a non-user causer cannot surface a mis-joined
     * user that merely shares the id.
     */
    public function getUserAttribute(): ?Model
    {
        $userMorph = (new ($this->resolveUserModel()))->getMorphClass();

        if ($this->causer_type !== null && $this->causer_type !== $userMorph) {
            return null;
        }

        return $this->getRelationValue('user');
    }

    /**
     * Most-recent-first.
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('created_at');
    }

    /**
     * Entries created within the last N hours.
     */
    public function scopeInLastHours(Builder $query, int $hours): Builder
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    /**
     * Entries caused by a given user id.
     */
    public function scopeByCauser(Builder $query, int|string $causerId): Builder
    {
        return $query->where('causer_id', $causerId);
    }

    /**
     * @return class-string<Model>
     */
    protected function resolveUserModel(): string
    {
        return config('auth.providers.users.model', User::class);
    }
}
