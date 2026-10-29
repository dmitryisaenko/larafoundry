<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Exceptions;

use Dmitryisaenko\LaraFoundry\Billing\Actions\RedeemPromoCodeAction;
use RuntimeException;

/**
 * Thrown when a discount promo code cannot be redeemed (sub-phase B5): it is
 * invalid/expired/exhausted, personal-mismatched, already used by this user, or
 * carries no gateway coupon/discount id for the active gateway.
 *
 * Raised by {@see RedeemPromoCodeAction}
 * inside its transaction, after the row lock, so the failure reflects the committed
 * state. The HTTP layer maps it to a single generic message (anti-oracle) — the
 * exception message itself is for logs, not the response body.
 */
final class PromoCodeUnavailableException extends RuntimeException {}
