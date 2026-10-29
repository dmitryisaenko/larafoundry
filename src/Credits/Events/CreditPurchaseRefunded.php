<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Events;

use Dmitryisaenko\LaraFoundry\Credits\Models\CreditPurchase;
use Dmitryisaenko\LaraFoundry\Credits\Models\CreditTransaction;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A credited purchase was refunded or charged back (v0.47.0) - once, after the
 * credits were taken back as far as the balance allowed (`$taken` is null when
 * nothing was left; `$purchase->credits_reversed` says how much). A host listens
 * to reverse its own follow-ups (a partner's commission). A refund that arrived
 * before its purchase does not fire it: nothing was ever credited.
 */
class CreditPurchaseRefunded
{
    use Dispatchable;

    public function __construct(
        public readonly CreditPurchase $purchase,
        public readonly ?CreditTransaction $taken,
        public readonly string $reason,
    ) {}
}
