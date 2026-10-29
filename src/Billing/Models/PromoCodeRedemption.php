<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per promo-code redemption (sub-phase B5).
 *
 * Replaces the donor's "look for a successful CompanyPayment" single-use check
 * (the add-on has no CompanyPayment, and that check trusted a fake payment). It is
 * both the single-use ledger — `unique (promo_code_id, user_id)` backs the
 * application guard against a concurrent double-redeem — and the audit trail for
 * every grant, paid or free-month.
 *
 * `redeemed_at` is fillable so the action stamps it explicitly inside its
 * transaction; the gateway columns record which PSP applied the discount, never a
 * self-computed charged amount.
 *
 * @property int $promo_code_id
 * @property int|string|null $company_id the tenant key: a company id (teams) or a user id (personal)
 * @property int|null $user_id
 * @property string $kind
 */
class PromoCodeRedemption extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'promo_code_id',
        'company_id',
        'user_id',
        'kind',
        'gateway',
        'gateway_reference',
        'redeemed_at',
    ];

    protected function casts(): array
    {
        return [
            'redeemed_at' => 'datetime',
        ];
    }

    /**
     * The redeemed code.
     */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }
}
