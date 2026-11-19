<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Exceptions;

use RuntimeException;

/**
 * A purchase of credits cannot be started (v0.47.0): the amount is not on sale
 * (CreditPriceList has no price for it), the user has no payer (a `teams` user
 * without an active company), or (v0.58.0) the buyer's country pays through
 * another gateway. Nothing was sent to the gateway.
 */
final class CreditPurchaseUnavailableException extends RuntimeException
{
    public static function notOnSale(int $credits): self
    {
        return new self("{$credits} credits are not on sale.");
    }

    public static function noPayer(): self
    {
        return new self('The user has no payer to buy credits for.');
    }

    /** v0.58.0: the buyer's country is routed to another gateway (CreditPurchaseRoutes). */
    public static function notForCountry(string $gateway, ?string $country): self
    {
        return new self("Credits are not sold through {$gateway} to buyers from ".($country ?? 'an unknown country').'.');
    }
}
