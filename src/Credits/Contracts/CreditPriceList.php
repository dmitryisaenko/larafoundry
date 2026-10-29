<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Contracts;

use Dmitryisaenko\LaraFoundry\Credits\Support\ConfigCreditPriceList;

/**
 * What credits cost to buy (v0.47.0) - the one source a purchase takes its price
 * from. The price is always the server's: a purchase sends the gateway this
 * figure and credits only when at least this much was paid, never a figure the
 * client supplied.
 *
 * The default, {@see ConfigCreditPriceList}, reads `larafoundry.credits.purchases`;
 * a host whose prices are admin settings binds its own.
 */
interface CreditPriceList
{
    /**
     * The price in minor units of the purchase currency for `$credits`, or null
     * when that amount is not on sale (below the minimum, above the maximum, or
     * not a bundle while custom amounts are off).
     */
    public function priceCents(int $credits): ?int;

    /**
     * The bundles on sale, smallest first.
     *
     * @return list<array{credits: int, price_cents: int}>
     */
    public function bundles(): array;

    /** The purchase currency, an ISO 4217 code in upper case. */
    public function currency(): string;
}
