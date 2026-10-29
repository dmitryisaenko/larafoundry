<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when a gateway operation is attempted but the gateway cannot take money:
 * no API secret, or the configured `billable_model` does not use the Cashier
 * Billable trait (the default is the core Company, which is gateway-agnostic by
 * design).
 *
 * This is the clean failure the driver raises instead of a fatal
 * "Call to undefined method newSubscription()": a host that installed the add-on
 * but has not yet pointed `billable_model` at its own Billable Company subclass
 * gets a clear, catchable error. `isConfigured()` returns false for the same
 * conditions, so well-behaved call sites branch to "billing not connected" before
 * ever reaching here.
 */
final class GatewayNotConfiguredException extends RuntimeException {}
