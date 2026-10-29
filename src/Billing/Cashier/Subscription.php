<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Cashier;

use Laravel\Paddle\Subscription as PaddleSubscription;

/**
 * Cashier-Paddle subscription model mapped to the add-on's own `paddle_subscriptions`
 * table. See {@see Customer} for why the add-on owns Paddle's tables under a
 * `paddle_` prefix and why the class basename is kept as `Subscription` (the
 * inherited items()/transactions() relations derive `subscription_id` from it).
 */
class Subscription extends PaddleSubscription
{
    protected $table = 'paddle_subscriptions';
}
