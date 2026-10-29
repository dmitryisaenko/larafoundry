<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Exceptions;

use Dmitryisaenko\LaraFoundry\Billing\Contracts\SandboxTesters;
use RuntimeException;

/**
 * A payment was started while the gateway is in its sandbox, by someone
 * {@see SandboxTesters} does not let through (v0.47.0). Nothing was sent to the
 * gateway. A host usually answers it as "not found": the checkout does not exist
 * for this person.
 */
final class SandboxPaymentNotAllowedException extends RuntimeException {}
