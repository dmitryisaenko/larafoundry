<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;

/**
 * A tracked login session — one row per authenticated device.
 *
 * Carries a device fingerprint (type/name/os/browser) and last-activity so the
 * host can show "active sessions" and offer "log out other devices". The row is
 * keyed to the framework session id, letting us reconcile it against the
 * `sessions` table.
 *
 * `active_company_id` (phase 1.2) binds the tracked session to its currently
 * active company — this is what makes "active company is per-device".
 * `pin_locked`/`pin_attempts`/`pin_locked_until` (phase 1.4) carry the per-session
 * PIN-lock state. `login_method` is identity-level (which provider the device
 * authenticated through).
 *
 * @property string $login_method
 * @property int|null $active_company_id
 * @property bool $pin_locked
 * @property int $pin_attempts
 * @property Carbon|null $pin_locked_until
 */
class UserSession extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'session_id',
        'active_company_id',
        'login_method',
        'ip_address',
        'user_agent',
        'user_device_type',
        'user_device_name',
        'user_os',
        'user_browser',
        'last_activity',
        'last_route_name',
        'pin_locked',
        'pin_attempts',
        'pin_locked_until',
    ];

    protected function casts(): array
    {
        return [
            'last_activity' => 'datetime',
            'pin_locked' => 'boolean',
            'pin_locked_until' => 'datetime',
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
