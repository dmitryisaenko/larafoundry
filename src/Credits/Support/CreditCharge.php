<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Support;

use Dmitryisaenko\LaraFoundry\Credits\Models\CreditTransaction;

/**
 * The outcome of charging a hold (v0.46.0).
 *
 * A charge is paid from the hold first and from the balance for any shortfall,
 * never taking the balance below zero. When even the balance cannot cover it,
 * the rest is `uncovered`: the work was done and not paid for. The ledger row
 * keeps it too (its `uncovered` column), so what was really paid is always
 * `cost - uncovered`; the caller decides what an uncovered charge means (flag
 * the account, stop further work).
 */
final class CreditCharge
{
    public function __construct(
        public readonly ?CreditTransaction $transaction,
        public readonly int $fromHold,
        public readonly int $fromBalance,
        public readonly int $uncovered,
    ) {}

    public static function nothing(): self
    {
        return new self(null, 0, 0, 0);
    }
}
