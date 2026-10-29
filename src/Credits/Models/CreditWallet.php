<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Models;

use Dmitryisaenko\LaraFoundry\Credits\Support\CreditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One payer's credit balance (v0.46.0).
 *
 * Never written directly: every change goes through {@see CreditService},
 * which locks this row, moves `balance` and writes the ledger row in the same
 * transaction - that is what keeps balance == SUM(transactions.amount).
 *
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property int $balance
 */
class CreditWallet extends Model
{
    protected $table = 'larafoundry_credit_wallets';

    protected $fillable = ['owner_type', 'owner_id'];

    protected function casts(): array
    {
        return [
            'owner_id' => 'integer',
            'balance' => 'integer',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class, 'wallet_id');
    }

    public function holds(): HasMany
    {
        return $this->hasMany(CreditHold::class, 'wallet_id');
    }
}
