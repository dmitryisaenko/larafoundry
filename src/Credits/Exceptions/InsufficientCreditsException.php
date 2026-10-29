<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Exceptions;

use RuntimeException;

/**
 * The wallet does not hold enough credits for a hold or a debit (v0.46.0).
 *
 * Thrown rather than returned as false: a refusal to spend is the one outcome a
 * caller must not overlook, and nothing has moved when it is thrown - the check
 * and the deduction happen under the same wallet lock.
 */
class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly int $balance,
        public readonly int $requested,
    ) {
        parent::__construct("Insufficient credits: {$requested} requested, {$balance} available.");
    }
}
