<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Qr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;

/**
 * A cross-device QR sign-in request (phase 1.4 part 2).
 *
 * The web (unauthenticated) side creates one and shows it as a QR; the already
 * authenticated device scans it and approves it; the web side polls until it is
 * approved and then logs in. `token` holds the SHA-256 hash of the secret that
 * travels in the QR — the plaintext is never persisted.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $token SHA-256 hash of the QR secret
 * @property Carbon $expires_at
 * @property bool $approved
 * @property Carbon|null $approved_at
 */
class SignInRequest extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'token',
        'expires_at',
        'ip_address',
        'user_agent',
        'approved',
        'approved_at',
        'approved_ip',
        'approved_user_agent',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'approved' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * The user who approved this request (null until approved).
     *
     * Resolves the host's configured authenticatable model rather than a
     * hard-coded class — the donor hard-coded `User::class`, which a package
     * must not assume.
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
