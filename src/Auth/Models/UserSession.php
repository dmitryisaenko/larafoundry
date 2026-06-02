<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User;

/**
 * A tracked login session — one row per authenticated device.
 *
 * Carries a device fingerprint (type/name/os/browser) and last-activity so the
 * host can show "active sessions" and offer "log out other devices". The row is
 * keyed to the framework session id, letting us reconcile it against the
 * `sessions` table.
 *
 * Company binding (`active_company_id`) and PIN lock from the legacy donor are
 * intentionally absent here: tenancy arrives in phase 1.2 with a real FK, and
 * PIN is deferred. `login_method` is kept — it is identity-level (which provider
 * the device authenticated through).
 *
 * @property string $login_method
 */
class UserSession extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'session_id',
        'login_method',
        'ip_address',
        'user_agent',
        'user_device_type',
        'user_device_name',
        'user_os',
        'user_browser',
        'last_activity',
        'last_route_name',
    ];

    protected function casts(): array
    {
        return [
            'last_activity' => 'datetime',
        ];
    }

    /**
     * The user this session belongs to.
     *
     * Resolves the host's configured authenticatable model rather than a
     * hard-coded class, so the package never assumes the app's User namespace.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo($this->resolveUserModel(), 'user_id');
    }

    /**
     * @return class-string<Model>
     */
    protected function resolveUserModel(): string
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model', User::class);

        return $model;
    }
}
