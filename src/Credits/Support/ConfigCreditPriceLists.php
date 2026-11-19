<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Support;

use Dmitryisaenko\LaraFoundry\Credits\Contracts\CreditPriceList;
use Dmitryisaenko\LaraFoundry\Credits\Contracts\CreditPriceLists;
use Dmitryisaenko\LaraFoundry\Credits\Contracts\CreditUnitValue;

/**
 * The default price lists by currency (v0.58.0): the bound CreditPriceList for
 * the main currency, and `larafoundry.credits.purchases.currencies.<CODE>` for
 * any other.
 *
 * The map is read whole and indexed by the code, never through a
 * `currencies.{$code}` dot path: a code with a dot in it would walk the config
 * tree instead of matching one entry.
 */
class ConfigCreditPriceLists implements CreditPriceLists
{
    public function __construct(
        private readonly CreditPriceList $main,
        private readonly CreditUnitValue $unitValue,
    ) {}

    public function in(?string $currency): ?CreditPriceList
    {
        if ($currency === null || trim($currency) === '') {
            return $this->main;
        }

        $code = strtoupper(trim($currency));

        if ($code === $this->main->currency()) {
            return $this->main;
        }

        $lists = config('larafoundry.credits.purchases.currencies', []);
        $settings = is_array($lists) ? ($lists[$code] ?? null) : null;

        return is_array($settings) ? new ConfigCreditPriceList($this->unitValue, $settings, $code) : null;
    }
}
