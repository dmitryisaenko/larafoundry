<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Contracts;

use Dmitryisaenko\LaraFoundry\Credits\Support\ConfigCreditPriceLists;

/**
 * The price lists by currency (v0.58.0): the main one ({@see CreditPriceList},
 * what Paddle sells at) and any other a gateway sells in - hryvnias for LiqPay.
 * Each list is a whole list of its own (round prices set by a person), never
 * the main one converted at a rate.
 *
 * The default, {@see ConfigCreditPriceLists}, gives the bound CreditPriceList for
 * its own currency and reads `larafoundry.credits.purchases.currencies` for the
 * rest; a host whose prices are admin settings binds its own.
 */
interface CreditPriceLists
{
    /**
     * The list for `$currency` (ISO 4217, any case), null for the main one.
     * Null when nothing is on sale in that currency.
     */
    public function in(?string $currency): ?CreditPriceList;
}
