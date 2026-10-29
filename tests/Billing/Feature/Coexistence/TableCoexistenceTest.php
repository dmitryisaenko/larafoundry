<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Cashier\Customer;
use Dmitryisaenko\LaraFoundry\Billing\Cashier\Subscription;
use Dmitryisaenko\LaraFoundry\Billing\Cashier\SubscriptionItem;
use Dmitryisaenko\LaraFoundry\Billing\Cashier\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Paddle\Cashier;

uses(RefreshDatabase::class);

/**
 * The DB-layer half of the coexistence claim (D-B4-1): a host running BOTH Stripe
 * and Paddle must have both packages' tables in one database. Cashier-Stripe and
 * Cashier-Paddle each ship `subscriptions` / `subscription_items` with different
 * schemas, so the add-on owns Paddle's under a `paddle_` prefix. This suite migrates
 * BOTH (MultiGatewayTestCase loads Cashier-Stripe's migrations and the provider loads
 * the add-on's paddle ones) and proves they no longer collide.
 *
 * The strongest proof is implicit: RefreshDatabase running both migration sets
 * without a "table already exists" error means the names are distinct. The
 * assertions below pin that down.
 */
it('migrates both gateways tables side by side without a name collision', function () {
    // Stripe's tables (its own names).
    expect(Schema::hasTable('subscriptions'))->toBeTrue()
        ->and(Schema::hasTable('subscription_items'))->toBeTrue();

    // Paddle's tables, owned by the add-on under the paddle_ prefix.
    expect(Schema::hasTable('paddle_customers'))->toBeTrue()
        ->and(Schema::hasTable('paddle_subscriptions'))->toBeTrue()
        ->and(Schema::hasTable('paddle_subscription_items'))->toBeTrue()
        ->and(Schema::hasTable('paddle_transactions'))->toBeTrue();

    // Paddle's generic `customers` name no longer exists (renamed away), so it can
    // never clash with a host table of that name either.
    expect(Schema::hasTable('customers'))->toBeFalse();
});

it('keeps the two subscriptions tables independent', function () {
    // Write a Paddle subscription; Stripe's identically-named-concept table must be
    // untouched — proving they are distinct tables, not one aliased over the other.
    DB::table('paddle_subscriptions')->insert([
        'billable_type' => 'company',
        'billable_id' => 1,
        'type' => 'default',
        'paddle_id' => 'sub_coexist_1',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('paddle_subscriptions')->count())->toBe(1)
        ->and(DB::table('subscriptions')->count())->toBe(0);
});

it('maps the prefixed models to the prefixed tables', function () {
    expect((new Customer)->getTable())->toBe('paddle_customers')
        ->and((new Subscription)->getTable())->toBe('paddle_subscriptions')
        ->and((new SubscriptionItem)->getTable())->toBe('paddle_subscription_items')
        ->and((new Transaction)->getTable())->toBe('paddle_transactions');

    // And Cashier-Paddle is actually using them.
    expect(Cashier::$subscriptionModel)
        ->toBe(Subscription::class)
        ->and(Cashier::$customerModel)
        ->toBe(Customer::class);
});

it('preserves the inherited relation foreign keys despite the table rename', function () {
    // The models keep the `Subscription` / `SubscriptionItem` basenames so Cashier's
    // convention-derived foreign key stays `subscription_id` (matching the migration
    // column), even though the table is now paddle_subscription_items.
    $items = (new Subscription)->items();

    expect($items->getForeignKeyName())->toBe('subscription_id');
});

it('round-trips a subscription and its items through the prefixed tables via Eloquent', function () {
    // The strongest guard against a future maintainer renaming these classes to
    // `Paddle*` (which would shift the derived FK to `paddle_subscription_id` and
    // silently break this): create a subscription and an item through the inherited
    // items() relation and prove the graph persists into and reloads from the
    // prefixed tables with the `subscription_id` key intact.
    $subscription = (new Subscription)->forceFill([
        'billable_type' => 'company',
        'billable_id' => 1,
        'type' => 'default',
        'paddle_id' => 'sub_roundtrip',
        'status' => 'active',
    ]);
    $subscription->save();

    $subscription->items()->forceCreate([
        'product_id' => 'pro_1',
        'price_id' => 'pri_pro_monthly',
        'status' => 'active',
        'quantity' => 1,
    ]);

    // Persisted into the prefixed item table, keyed by the subscription's local id.
    expect(DB::table('paddle_subscription_items')
        ->where('subscription_id', $subscription->getKey())
        ->where('price_id', 'pri_pro_monthly')
        ->exists())->toBeTrue();

    // Reloads through the relation against the prefixed table.
    $reloaded = Subscription::query()->whereKey($subscription->getKey())->first();

    expect($reloaded->items)->toHaveCount(1)
        ->and($reloaded->items->first()->getTable())->toBe('paddle_subscription_items')
        ->and($reloaded->items->first()->subscription->is($reloaded))->toBeTrue();
});
