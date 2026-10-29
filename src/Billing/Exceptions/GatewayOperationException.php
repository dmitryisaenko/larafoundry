<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when a gateway is configured but a specific operation cannot proceed:
 * no Stripe price id mapped for the requested plan+period, or no billable record
 * found for the tenant.
 *
 * Distinct from {@see GatewayNotConfiguredException} (which means "this gateway
 * can't take money at all"): this means "the gateway is live, but this particular
 * call is missing data". Notably, a missing price id fails loudly here rather than
 * letting Cashier charge a wrong or zero amount.
 */
final class GatewayOperationException extends RuntimeException {}
