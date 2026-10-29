<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when an app-side free month cannot be granted to a company (sub-phase B5):
 * the company already used its free month (per-company eligibility, D-B5-3), the
 * requested period is not monthly (free month is monthly-only, donor parity), or
 * the free-month offer is disabled in config.
 *
 * Distinct from the gateway exceptions: a free month is an app-side grant that
 * never touches a payment gateway, so its failure is its own kind.
 */
final class FreeMonthUnavailableException extends RuntimeException {}
