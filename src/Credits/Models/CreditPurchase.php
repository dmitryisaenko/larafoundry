<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Models;

use Dmitryisaenko\LaraFoundry\Credits\Support\CreditPurchaseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One payment that bought credits (v0.47.0). Written only by
 * {@see CreditPurchaseService}.
 *
 * A `pending` row is a checkout that was opened and not yet paid: it carries
 * the credits and the price the server set, and the payment's confirmation is
 * checked against it - never against data that travelled with the payment.
 * A `refund_pending` row is a refund that arrived before its purchase was
 * confirmed; the confirmation then settles it and credits nothing (see the
 * migration). `credits_reversed` is what a refund actually took back - less than
 * `credits` when some were already spent, since a refund never takes the balance
 * below zero.
 *
 * @property int $id
 * @property string|null $owner_type
 * @property int|null $owner_id
 * @property int|null $user_id
 * @property int $credits
 * @property int $credits_reversed
 * @property int $amount_cents
 * @property string|null $currency
 * @property string $gateway
 * @property string|null $reference
 * @property string|null $refund_key
 * @property string $status
 * @property bool $sandbox
 * @property string|null $note
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $paid_at
 * @property Carbon|null $refunded_at
 * @property Carbon $created_at
 */
class CreditPurchase extends Model
{
    /** A checkout was opened; not paid yet. */
    public const STATUS_PENDING = 'pending';

    /** Paid and credited. */
    public const STATUS_COMPLETED = 'completed';

    /** The money went back; the credits were taken back as far as the balance allowed. */
    public const STATUS_REFUNDED = 'refunded';

    /** A refund that arrived before its purchase, waiting for it. */
    public const STATUS_REFUND_PENDING = 'refund_pending';

    protected $table = 'larafoundry_credit_purchases';

    protected $fillable = [
        'owner_type', 'owner_id', 'user_id', 'credits', 'credits_reversed', 'amount_cents', 'currency',
        'gateway', 'reference', 'refund_key', 'status', 'sandbox', 'note', 'meta', 'paid_at', 'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'owner_id' => 'integer',
            'user_id' => 'integer',
            'credits' => 'integer',
            'credits_reversed' => 'integer',
            'amount_cents' => 'integer',
            'sandbox' => 'boolean',
            'meta' => 'array',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED;
    }
}
