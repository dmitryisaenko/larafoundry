<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Cashier;

use Laravel\Paddle\Customer as PaddleCustomer;

/**
 * Cashier-Paddle customer model mapped to the add-on's own `paddle_customers`
 * table (sub-phase B4 follow-up: gateway table un-collision).
 *
 * WHY this class exists: Cashier-Stripe and Cashier-Paddle each ship migrations
 * that create `subscriptions` / `subscription_items` (and Paddle a generic
 * `customers`) with DIFFERENT schemas. A host running BOTH gateways — the multi-
 * gateway-by-region routing B2 was built for — would have the two packages fight
 * over the same table names. The add-on therefore owns Paddle's tables under a
 * `paddle_` prefix and points Cashier-Paddle at these models via
 * `Cashier::useXModel()` in the service provider.
 *
 * IMPORTANT — same class basename, different namespace: the class is named
 * `Customer` (not `PaddleCustomer`) on purpose. Cashier-Paddle's relations derive
 * their foreign keys from the model's class basename (e.g.
 * Subscription::items() → `subscription_id`). Renaming the basename would shift the
 * derived keys (`paddle_subscription_id`) and break the inherited relations against
 * the faithfully-copied migration columns. Overriding only `$table` keeps every
 * inherited relation working while moving the data to the prefixed table.
 */
class Customer extends PaddleCustomer
{
    protected $table = 'paddle_customers';
}
