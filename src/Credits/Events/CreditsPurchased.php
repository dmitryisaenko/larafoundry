<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Events;

use Dmitryisaenko\LaraFoundry\Credits\Models\CreditPurchase;
use Dmitryisaenko\LaraFoundry\Credits\Models\CreditTransaction;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A purchase was recorded and its credits added (v0.47.0) - once per purchase,
 * after the money is committed; a re-delivered confirmation does not fire it
 * again. A host listens for its own follow-ups (a partner's commission, a
 * receipt). A listener that fails is reported and does not undo the purchase.
 */
class CreditsPurchased
{
    use Dispatchable;

    public function __construct(
        public readonly CreditPurchase $purchase,
        public readonly ?CreditTransaction $credit,
    ) {}
}
