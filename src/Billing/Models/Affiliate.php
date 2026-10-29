<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Models;

use Dmitryisaenko\LaraFoundry\Billing\Support\AffiliateCodeGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as DefaultUser;

/**
 * A partner in the affiliate program (Ф4.4).
 *
 * Owns the referral `code` (kept off the core `users` table so installing the add-on
 * never bloats a host's users schema) and the optional per-affiliate commission
 * override. The link to the host user is `user_id` resolved through the configured auth
 * model, never a hardcoded class — the add-on does not own that model (mirrors
 * {@see CompanyPayment::user()}).
 *
 * @property int $id
 * @property int $user_id
 * @property string $code
 * @property int|null $commission_rate_bp
 * @property string $status
 */
class Affiliate extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_PENDING = 'pending';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'code',
        'commission_rate_bp',
        'status',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'commission_rate_bp' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * The host user behind this partner (the configured auth model, never hardcoded).
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model', DefaultUser::class);

        return $this->belongsTo($model, 'user_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(AffiliateCommission::class);
    }

    /**
     * @param  Builder<Affiliate>  $query
     * @return Builder<Affiliate>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * The partner identity for a user, created if absent (auto eligibility / opt-in).
     *
     * firstOrCreate against the unique `user_id` so a concurrent call cannot make two
     * rows. A fresh row gets a generated unique code and the given status (active by
     * default — auto mode; opt-in with approval passes pending).
     */
    public static function ensureFor(int $userId, string $status = self::STATUS_ACTIVE): self
    {
        return static::query()->firstOrCreate(
            ['user_id' => $userId],
            [
                'code' => app(AffiliateCodeGenerator::class)->generate(),
                'status' => $status,
                'approved_at' => $status === self::STATUS_ACTIVE ? now() : null,
            ],
        );
    }

    /**
     * Effective commission rate in basis points for the percent basis: the per-affiliate
     * override when set, else the config default (a percent number coerced to bp).
     */
    public function effectiveRateBp(): int
    {
        if ($this->commission_rate_bp !== null) {
            return max(0, (int) $this->commission_rate_bp);
        }

        return self::percentToBp(config('larafoundry-billing.affiliate.commission.percent.default_rate', 0));
    }

    /**
     * Coerce a percent number (possibly fractional, e.g. 12.5) to basis points (1250),
     * clamped non-negative. Junk becomes 0 (fail-closed: no rate, never a wild grant).
     *
     * Accepts a NUMERIC STRING too ("20") — a host that sets the rate via the documented
     * `LARAFOUNDRY_BILLING_AFFILIATE_PERCENT` env gets a string, and silently dropping it
     * to 0 would stop every commission with no error.
     */
    public static function percentToBp(mixed $percent): int
    {
        if (! is_numeric($percent)) {
            return 0;
        }

        return max(0, (int) round(((float) $percent) * 100));
    }
}
