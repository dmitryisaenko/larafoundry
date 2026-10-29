<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Models;

use Dmitryisaenko\LaraFoundry\Credits\Support\CreditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Credits set aside for one piece of work (v0.46.0). One per work (`subject`).
 *
 * Written only by {@see CreditService}: hold() creates it, charge() spends
 * from it, release() returns the remainder and closes it.
 *
 * @property int $id
 * @property int $wallet_id
 * @property string $subject_type
 * @property int $subject_id
 * @property int $amount
 * @property int $remaining
 * @property int $spent
 * @property Carbon|null $closed_at
 */
class CreditHold extends Model
{
    protected $table = 'larafoundry_credit_holds';

    protected $fillable = ['wallet_id', 'subject_type', 'subject_id', 'amount', 'remaining', 'spent'];

    protected function casts(): array
    {
        return [
            'wallet_id' => 'integer',
            'subject_id' => 'integer',
            'amount' => 'integer',
            'remaining' => 'integer',
            'spent' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CreditWallet::class, 'wallet_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class, 'hold_id');
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }
}
