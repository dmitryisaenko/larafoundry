<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Contracts;

use Dmitryisaenko\LaraFoundry\Credits\Support\ConfigCreditUnitValue;

/**
 * What one credit is worth in cents, right now (v0.46.0).
 *
 * The ledger snapshots it onto every row, so the value of past movements never
 * follows a later price change. The default reads
 * `larafoundry.credits.unit_cents` ({@see ConfigCreditUnitValue}); a host whose
 * price is edited at runtime (an admin setting) rebinds this contract.
 */
interface CreditUnitValue
{
    public function cents(): float;
}
