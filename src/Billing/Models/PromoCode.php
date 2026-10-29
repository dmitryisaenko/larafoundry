<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as DefaultUser;
use Illuminate\Support\Carbon;

/**
 * A promo code (sub-phase B5), ported from the donor's `App\Models\PromoCode`.
 *
 * What stayed: `isValid()`, `getStatus()`, `incrementUsage()`, `isPersonal()`, and
 * the shape of `canBeUsed()`. What changed for the add-on:
 *
 *  - The single-use-per-user check reads {@see PromoCodeRedemption}, not the
 *    donor's successful-`CompanyPayment` query (the add-on has no CompanyPayment,
 *    and that check trusted a fake payment).
 *  - `discount_value` is an INT (minor units / 0–100 percentage), not a float —
 *    money in this add-on is minor units and the gateway computes every charge.
 *  - `kind` + `stripe_coupon_id`/`paddle_discount_id` carry the hybrid mapping
 *    (D-B5-1): the registry owns validity, the gateway owns the amount.
 *  - `calculateDiscount()` is kept but is **preview/estimate only** — it is NEVER
 *    the charged amount. Stripe Coupons / Paddle Discounts apply the real discount
 *    and report the final figure by webhook.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $code
 * @property string $kind
 * @property string $discount_type
 * @property int $discount_value
 * @property int|null $max_uses
 * @property int $used_count
 * @property bool $single_use_per_user
 * @property bool $is_active
 * @property Carbon|null $expires_at
 */
class PromoCode extends Model
{
    public const KIND_DISCOUNT = 'discount';

    public const KIND_FREE_MONTH = 'free_month';

    /**
     * The authorization ability a host registers (via Gate::define) to say who may
     * manage promo codes. The add-on does not know the host's admin model, so the
     * admin FormRequests check this gate and fail closed when it is undefined
     * (decision D-B5-4: backend ships the seam, the host wires the policy).
     */
    public const MANAGE_ABILITY = 'larafoundry-billing.manage-promo-codes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'code',
        'description',
        'kind',
        'discount_type',
        'discount_value',
        'stripe_coupon_id',
        'paddle_discount_id',
        'max_uses',
        'used_count',
        'single_use_per_user',
        'is_active',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'single_use_per_user' => 'boolean',
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            // Whole units, not the donor's decimal:2 — the gateway, not the app,
            // computes every charged amount.
            'discount_value' => 'integer',
            'max_uses' => 'integer',
            'used_count' => 'integer',
        ];
    }

    /**
     * Redemptions of this code (the single-use ledger + audit trail).
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoCodeRedemption::class);
    }

    /**
     * The owner of a personal code (admin console display only).
     *
     * Resolves the host's configured auth user model — the add-on does not own that
     * class — mirroring {@see CompanyPayment::user()}. Null for a general code.
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model', DefaultUser::class);

        return $this->belongsTo($model, 'user_id');
    }

    /**
     * Whether the code is active and unexpired.
     */
    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Whether the code can be redeemed by the given user.
     *
     * Fail-closed by construction: every failing branch returns false. The
     * single-use-per-user check reads the redemption ledger (add-on replacement
     * for the donor's CompanyPayment query) so a user who already redeemed cannot
     * redeem again. The personal-code check matches `user_id` server-side — the
     * caller passes the authenticated user, never a client-supplied value, so a
     * personal code cannot be redeemed by anyone else (IDOR guard).
     *
     * `$companyId` is accepted for symmetry with the free-month eligibility path
     * (per-company, D-B5-3) and future per-tenant rules; it is not yet consulted
     * here so it has no behavioural effect on the per-user ledger.
     */
    public function canBeUsed(?int $userId = null, int|string|null $companyId = null): bool
    {
        if (! $this->isValid()) {
            return false;
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return false;
        }

        // Personal code: only its owner may use it.
        if ($this->user_id !== null && $userId !== $this->user_id) {
            return false;
        }

        // Single-use per user: refuse if this user already has a redemption row.
        if ($this->single_use_per_user && $userId !== null) {
            $alreadyUsed = $this->redemptions()
                ->where('user_id', $userId)
                ->exists();

            if ($alreadyUsed) {
                return false;
            }
        }

        return true;
    }

    /**
     * PREVIEW ONLY — never the charged amount.
     *
     * A UI estimate of the discount on a minor-units amount, so a checkout screen
     * can show "you save X" before redirecting to the gateway. The real discount is
     * applied by the gateway (Stripe Coupon / Paddle Discount) and the final charged
     * figure comes back on the webhook. Computing the charge in the app would create
     * a second source of truth about money — exactly the donor anti-pattern B5 drops.
     */
    public function calculateDiscount(int $amount): int
    {
        if ($this->discount_type === 'percentage') {
            return (int) round(($amount * $this->discount_value) / 100);
        }

        // Fixed discount, capped at the amount (never negative total).
        return (int) min($this->discount_value, $amount);
    }

    /**
     * The gateway catalogue id this code maps onto, for the given gateway.
     *
     * The hybrid mapping (D-B5-1): the driver hands this id to the gateway, which
     * applies and computes the discount. Null when the code carries no id for that
     * gateway (e.g. a free-month code, or a discount code not wired for that PSP).
     */
    public function couponIdFor(string $gateway): ?string
    {
        $id = match ($gateway) {
            'stripe' => $this->stripe_coupon_id,
            'paddle' => $this->paddle_discount_id,
            default => null,
        };

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Increment the global usage counter (call inside the redemption transaction).
     */
    public function incrementUsage(): void
    {
        $this->increment('used_count');
    }

    /**
     * Whether this is a personal (owner-bound) code.
     */
    public function isPersonal(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * Whether this code grants an app-side free month rather than a gateway discount.
     */
    public function isFreeMonth(): bool
    {
        return $this->kind === self::KIND_FREE_MONTH;
    }

    /**
     * Human-readable lifecycle status (inactive | expired | exhausted | active).
     */
    public function getStatus(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return 'expired';
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return 'exhausted';
        }

        return 'active';
    }
}
