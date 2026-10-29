<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Exceptions;

use RuntimeException;

/**
 * A purchase of credits cannot be started (v0.47.0): the amount is not on sale
 * (CreditPriceList has no price for it) or the user has no payer (a `teams` user
 * without an active company). Nothing was sent to the gateway.
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
}
