<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Cashier;

use Laravel\Paddle\Transaction as PaddleTransaction;

/**
 * Cashier-Paddle transaction model mapped to `paddle_transactions`. See
 * {@see Customer} for the rationale; basename kept as `Transaction`.
 */
class Transaction extends PaddleTransaction
{
    protected $table = 'paddle_transactions';
}
