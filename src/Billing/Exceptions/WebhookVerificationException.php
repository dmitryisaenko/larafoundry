<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when an incoming billing webhook fails signature verification.
 *
 * A distinct type so the add-on's webhook controller can map it to a 400/403
 * without swallowing unrelated runtime errors. The null driver throws it for
 * every webhook (the free core never has a verified provider).
 */
class WebhookVerificationException extends RuntimeException {}
