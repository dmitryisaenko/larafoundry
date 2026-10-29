<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Commission;

/**
 * The computed commission for one qualifying payment (Ф4.4): an amount in whole MINOR
 * UNITS (the payment's currency), plus an audit snapshot of how it was derived.
 *
 * `rateBp` is set only for the percent basis (the basis-points rate applied); null for
 * the fixed / per_plan bases, where the amount is read straight from config.
 */
final class CommissionResult
{
    public function __construct(
        public readonly int $amount,
        public readonly string $basis,
        public readonly ?int $rateBp = null,
    ) {}
}
