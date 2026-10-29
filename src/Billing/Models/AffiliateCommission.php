<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One commission row per qualifying payment of a referred company (Ф4.4) — the payout
 * ledger.
 *
 * Accrued pending by the AccrueAffiliateCommission listener; a super-admin approves then
 * marks it paid (payout is manual — the add-on has no money-out PSP), or it is voided as
 * a clawback when the source payment later fails/refunds. `amount` is minor units in the
 * payment's `currency`; `basis`/`rate_bp` snapshot how it was computed.
 *
 * @property int $id
 * @property int $affiliate_id
 * @property int $referral_id
 * @property int $company_payment_id
 * @property int $company_id the tenant key: a company id (teams) or a user id (personal)
 * @property string $currency
 * @property int $amount
 * @property string $status
 * @property string $basis
 * @property int|null $rate_bp
 */
class AffiliateCommission extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'affiliate_id',
        'referral_id',
        'company_payment_id',
        'company_id',
        'currency',
        'amount',
        'status',
        'basis',
        'rate_bp',
        'approved_at',
        'paid_at',
        'payout_reference',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'rate_bp' => 'integer',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(CompanyPayment::class, 'company_payment_id');
    }

    /**
     * @param  Builder<AffiliateCommission>  $query
     * @return Builder<AffiliateCommission>
     */
    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function markApproved(): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_at' => now(),
        ]);
    }

    public function markPaid(?string $reference = null): void
    {
        $this->update([
            'status' => self::STATUS_PAID,
            'paid_at' => now(),
            'payout_reference' => $reference,
        ]);
    }

    /**
     * Clawback: void the commission (a later failed/refunded source payment). Kept as a
     * status, not a delete, so the ledger retains the audit trail.
     */
    public function void(): void
    {
        $this->update(['status' => self::STATUS_VOID]);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }
}
