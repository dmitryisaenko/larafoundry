<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Support;

use Dmitryisaenko\LaraFoundry\Credits\Contracts\CreditUnitValue;

/**
 * The default credit value: `larafoundry.credits.unit_cents` from config.
 */
class ConfigCreditUnitValue implements CreditUnitValue
{
    public function cents(): float
    {
        return max(0.0, (float) config('larafoundry.credits.unit_cents', 1));
    }
}
