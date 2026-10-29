<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Cashier;

use Laravel\Paddle\SubscriptionItem as PaddleSubscriptionItem;

/**
 * Cashier-Paddle subscription-item model mapped to `paddle_subscription_items`.
 * See {@see Customer} for the rationale; basename kept as `SubscriptionItem` so the
 * inherited subscription() belongsTo resolves the `subscription_id` foreign key.
 */
class SubscriptionItem extends PaddleSubscriptionItem
{
    protected $table = 'paddle_subscription_items';
}
