<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Models;

use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A pending invitation for an email to join a company (phase 1.2).
 *
 * The token is a 64-char random string (kept from the donor — strong enough that
 * guessing is infeasible) and is the only thing the public accept/reject routes
 * accept. Acceptance additionally verifies the logged-in user's email matches
 * the invited email, so a leaked token alone can't bind an attacker's account.
 *
 * @property string $email
 * @property int|null $role_id
 * @property string $token
 * @property string $status
 * @property Carbon|null $expires_at
 */
class CompanyInvitation extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'email',
        'role_id',
        'token',
        'status',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * Generate a fresh, unguessable invitation token.
     */
    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public function company(): BelongsTo
    {
        // Resolve the configured (host) company model, not the base class, so a
        // host subclass's columns/scopes/accessors apply in invitation flows —
        // consistent with how BelongsToTenancy/BelongsToTenant resolve it.
        /** @var class-string<Company> $model */
        $model = config('larafoundry.tenancy.company_model', Company::class);

        return $this->belongsTo($model, 'company_id');
    }

    /**
     * The user who issued the invitation (nullable — `invited_by` may be unset
     * for imported/system invites). Used to fill the `inviter_name` template
     * variable in the invitation email (phase 5.1).
     *
     * @return BelongsTo<Model, $this>
     */
    public function inviter(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        return $this->belongsTo($model, 'invited_by');
    }

    /**
     * The company-scoped role to grant the invitee on acceptance (role-at-invite).
     *
     * Nullable — the default invite carries no role ("Specify later"). The role is
     * always a company role (validated company-scoped at invite time); on accept
     * the controller re-checks it still exists and belongs to this invitation's
     * company before assigning, so a deleted/foreign role degrades to no grant.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Whether the invitation is still pending and unexpired.
     */
    public function isActionable(): bool
    {
        return $this->status === self::STATUS_PENDING && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        // A missing expiry counts as expired (fail-closed): the schema forbids
        // null, so a null here means a malformed/imported row, not a valid
        // never-expiring token.
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    /**
     * Whether the invitation was addressed to the given email (case-insensitive).
     */
    public function isFor(string $email): bool
    {
        return mb_strtolower($this->email) === mb_strtolower($email);
    }

    /**
     * Only pending, unexpired invitations.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('expires_at', '>', now());
    }
}
