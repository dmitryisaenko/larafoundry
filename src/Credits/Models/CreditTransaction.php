<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Models;

use Dmitryisaenko\LaraFoundry\Credits\Support\CreditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;

/**
 * One ledger row (v0.46.0). Written only by {@see CreditService}, never edited.
 *
 * `kind` is what the movement did (the constants below); `reason` is the host's
 * word for why. `amount` is how the balance moved; `cost` is what a charge cost,
 * `uncovered` the part of it nobody paid (see the migration for why a row
 * carries all three).
 *
 * @property int $id
 * @property int $wallet_id
 * @property int|null $hold_id
 * @property string $kind
 * @property string|null $reason
 * @property int $amount
 * @property int $cost
 * @property int $uncovered
 * @property int $balance_after
 * @property string|null $value_cents
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $note
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 */
class CreditTransaction extends Model
{
    /** Credits set aside for work: the balance goes down, the hold up. */
    public const KIND_HOLD = 'hold';

    /** Work paid from its hold; the balance moves only by a shortfall. */
    public const KIND_CHARGE = 'charge';

    /** A hold's remainder returned to the balance; the hold is closed. */
    public const KIND_RELEASE = 'release';

    /** Credits added: a purchase, a gift, a grant. */
    public const KIND_CREDIT = 'credit';

    /** Credits spent at once, without a hold. */
    public const KIND_DEBIT = 'debit';

    /** The balance set to a figure: a correction. */
    public const KIND_ADJUST = 'adjust';

    public const KINDS = [
        self::KIND_HOLD,
        self::KIND_CHARGE,
        self::KIND_RELEASE,
        self::KIND_CREDIT,
        self::KIND_DEBIT,
        self::KIND_ADJUST,
    ];

    protected $table = 'larafoundry_credit_transactions';

    protected $fillable = [
        'wallet_id', 'hold_id', 'kind', 'reason', 'amount', 'cost', 'uncovered', 'balance_after',
        'value_cents', 'subject_type', 'subject_id', 'note', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'wallet_id' => 'integer',
            'hold_id' => 'integer',
            'amount' => 'integer',
            'cost' => 'integer',
            'uncovered' => 'integer',
            'balance_after' => 'integer',
            'value_cents' => 'decimal:4',
            'subject_id' => 'integer',
            'meta' => 'array',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CreditWallet::class, 'wallet_id');
    }

    public function hold(): BelongsTo
    {
        return $this->belongsTo(CreditHold::class, 'hold_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The row's label for a reader: the host's wording for the reason when it has
     * one (`larafoundry::credits.reasons.<reason>`, added by overriding the core's
     * lang file), otherwise the core's wording for the kind.
     */
    public function label(?string $locale = null): string
    {
        if ($this->reason !== null) {
            $key = 'larafoundry::credits.reasons.'.$this->reason;

            if (Lang::has($key, $locale)) {
                return (string) __($key, [], $locale);
            }
        }

        return (string) __('larafoundry::credits.kinds.'.$this->kind, [], $locale);
    }
}
