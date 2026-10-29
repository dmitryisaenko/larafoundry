<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Support\SubscriptionState;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\PaddleBillableCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Paddle\Events\WebhookReceived;

uses(RefreshDatabase::class);

/**
 * The webhook mirror is the only writer of the core's access columns. These tests
 * dispatch a verified Cashier-Paddle WebhookReceived event (Cashier has already
 * checked the signature upstream) and assert the derived state lands on `companies`
 * — proving the FREE core's gate reads current data with no Paddle call here, and
 * that a Paddle-paid tenant surfaces identically to a Stripe-paid one.
 *
 * Unlike the Stripe mirror, the tenant ↔ gateway link is the polymorphic Paddle
 * `customers` row (paddle_id -> billable morph), not a column on the company.
 */
function billableWithPaddleId(string $paddleId): PaddleBillableCompany
{
    // A founder row first: companies.created_by_id is a FK to users.
    $userId = DB::table('users')->insertGetId([
        'name' => 'Owner',
        'email' => "owner+{$paddleId}@example.test",
        'password' => bcrypt('secret'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $company = new PaddleBillableCompany;
    $company->forceFill([
        'uuid' => (string) Str::uuid(),
        'name' => 'Acme',
        'slug' => "acme-{$paddleId}",
        'country' => 'UA',
        'created_by_id' => $userId,
    ])->save();

    // Link the company to a Paddle customer the way Cashier-Paddle does: a row in
    // its polymorphic customers table — owned by the add-on as `paddle_customers`
    // (the un-collision rename). findBillable($paddleId) resolves through it.
    DB::table('paddle_customers')->insert([
        'billable_type' => $company->getMorphClass(),
        'billable_id' => $company->getKey(),
        'paddle_id' => $paddleId,
        'name' => 'Acme',
        'email' => "billing+{$paddleId}@example.test",
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $company->refresh();
}

function paddleSubscriptionEvent(string $type, array $data): WebhookReceived
{
    return new WebhookReceived([
        'event_type' => $type,
        'data' => array_merge(['customer_id' => 'ctm_default'], $data),
    ]);
}

it('mirrors an updated subscription into the core columns', function () {
    $company = billableWithPaddleId('ctm_upd');
    $periodEnd = Carbon::now()->addMonth()->startOfSecond();

    event(paddleSubscriptionEvent('subscription.updated', [
        'customer_id' => 'ctm_upd',
        'status' => 'active',
        'current_billing_period' => ['ends_at' => $periodEnd->toIso8601String()],
        'items' => [['price' => ['id' => 'pri_pro_monthly']]],
    ]));

    $company->refresh();

    expect($company->subscription_ends_at->getTimestamp())->toBe($periodEnd->getTimestamp())
        ->and($company->plan_id)->toBe('pro')
        ->and($company->trial_ends_at)->toBeNull()
        ->and(SubscriptionState::for($company)->hasActiveSubscription())->toBeTrue();
});

it('mirrors a scheduled cancellation to its effective date', function () {
    // A scheduled cancel keeps access until the effective date, not the period end.
    $company = billableWithPaddleId('ctm_sched');
    $effectiveAt = Carbon::now()->addDays(10)->startOfSecond();

    event(paddleSubscriptionEvent('subscription.updated', [
        'customer_id' => 'ctm_sched',
        'status' => 'active',
        'current_billing_period' => ['ends_at' => Carbon::now()->addMonth()->toIso8601String()],
        'scheduled_change' => ['action' => 'cancel', 'effective_at' => $effectiveAt->toIso8601String()],
        'items' => [['price' => ['id' => 'pri_pro_monthly']]],
    ]));

    $company->refresh();

    expect($company->subscription_ends_at->getTimestamp())->toBe($effectiveAt->getTimestamp())
        ->and(SubscriptionState::for($company)->hasActiveSubscription())->toBeTrue();
});

it('mirrors a trialing subscription trial end', function () {
    $company = billableWithPaddleId('ctm_trial');
    $trialEnd = Carbon::now()->addDays(14)->startOfSecond();

    event(paddleSubscriptionEvent('subscription.updated', [
        'customer_id' => 'ctm_trial',
        'status' => 'trialing',
        'current_billing_period' => ['ends_at' => $trialEnd->toIso8601String()],
        'items' => [[
            'price' => ['id' => 'pri_pro_yearly'],
            'trial_dates' => ['ends_at' => $trialEnd->toIso8601String()],
        ]],
    ]));

    $company->refresh();

    expect($company->trial_ends_at->getTimestamp())->toBe($trialEnd->getTimestamp())
        ->and($company->plan_id)->toBe('pro')
        ->and(SubscriptionState::for($company)->isOnTrial())->toBeTrue();
});

it('ends access when a subscription is canceled', function () {
    $company = billableWithPaddleId('ctm_del');
    $company->forceFill(['subscription_ends_at' => Carbon::now()->addMonth()])->save();

    event(paddleSubscriptionEvent('subscription.canceled', [
        'customer_id' => 'ctm_del',
        'status' => 'canceled',
    ]));

    $company->refresh();

    expect($company->subscription_ends_at->isFuture())->toBeFalse()
        ->and(SubscriptionState::for($company)->hasActiveSubscription())->toBeFalse();
});

it('ignores webhook events for an unknown customer', function () {
    $company = billableWithPaddleId('ctm_known');

    event(paddleSubscriptionEvent('subscription.updated', [
        'customer_id' => 'ctm_someone_else',
        'status' => 'active',
        'current_billing_period' => ['ends_at' => Carbon::now()->addMonth()->toIso8601String()],
    ]));

    $company->refresh();

    // Untouched: the event named a different customer.
    expect($company->subscription_ends_at)->toBeNull();
});

it('ignores non-subscription webhook events', function () {
    $company = billableWithPaddleId('ctm_txn');

    event(new WebhookReceived([
        'event_type' => 'transaction.completed',
        'data' => ['customer_id' => 'ctm_txn'],
    ]));

    $company->refresh();

    expect($company->subscription_ends_at)->toBeNull()
        ->and($company->plan_id)->toBeNull();
});
