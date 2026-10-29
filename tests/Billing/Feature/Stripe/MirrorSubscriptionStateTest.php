<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Support\SubscriptionState;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Cashier\Events\WebhookReceived;

uses(RefreshDatabase::class);

/**
 * The webhook mirror is the only writer of the core's access columns. These tests
 * dispatch a verified Cashier WebhookReceived event (Cashier has already checked
 * the signature upstream) and assert the derived state lands on `companies` —
 * proving the FREE core's gate reads current data with no Stripe call here.
 */
function billableWithStripeId(string $stripeId): BillableCompany
{
    // A founder row first: companies.created_by_id is a FK to users.
    $userId = DB::table('users')->insertGetId([
        'name' => 'Owner',
        'email' => "owner+{$stripeId}@example.test",
        'password' => bcrypt('secret'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $company = new BillableCompany;
    $company->forceFill([
        'uuid' => (string) Str::uuid(),
        'name' => 'Acme',
        'slug' => "acme-{$stripeId}",
        'country' => 'UA',
        'created_by_id' => $userId,
        'stripe_id' => $stripeId,
    ])->save();

    return $company->refresh();
}

function subscriptionEvent(string $type, array $object): WebhookReceived
{
    return new WebhookReceived([
        'type' => $type,
        'data' => ['object' => array_merge(['customer' => 'cus_default'], $object)],
    ]);
}

it('mirrors an updated subscription into the core columns', function () {
    $company = billableWithStripeId('cus_upd');
    $periodEnd = Carbon::now()->addMonth()->startOfSecond();

    event(subscriptionEvent('customer.subscription.updated', [
        'customer' => 'cus_upd',
        'status' => 'active',
        'current_period_end' => $periodEnd->getTimestamp(),
        'items' => ['data' => [['price' => ['id' => 'price_pro_monthly']]]],
    ]));

    $company->refresh();

    expect($company->subscription_ends_at->getTimestamp())->toBe($periodEnd->getTimestamp())
        ->and($company->plan_id)->toBe('pro')
        ->and($company->trial_ends_at)->toBeNull()
        ->and(SubscriptionState::for($company)->hasActiveSubscription())->toBeTrue();
});

it('mirrors the period end from the item level (basil API shape)', function () {
    // Under the Stripe basil API (the version Cashier 16 pins), the subscription
    // object has NO top-level current_period_end — it lives only on each item. The
    // mirror must still find it, or an active subscription is wrongly recorded as
    // ended (NULL) and access revoked.
    $company = billableWithStripeId('cus_basil');
    $periodEnd = Carbon::now()->addMonth()->startOfSecond();

    event(subscriptionEvent('customer.subscription.updated', [
        'customer' => 'cus_basil',
        'status' => 'active',
        // no top-level current_period_end — basil shape
        'items' => ['data' => [[
            'current_period_end' => $periodEnd->getTimestamp(),
            'price' => ['id' => 'price_pro_monthly'],
        ]]],
    ]));

    $company->refresh();

    expect($company->subscription_ends_at->getTimestamp())->toBe($periodEnd->getTimestamp())
        ->and($company->plan_id)->toBe('pro')
        ->and(SubscriptionState::for($company)->hasActiveSubscription())->toBeTrue();
});

it('mirrors a trialing subscription trial end', function () {
    $company = billableWithStripeId('cus_trial');
    $trialEnd = Carbon::now()->addDays(14)->startOfSecond();

    event(subscriptionEvent('customer.subscription.updated', [
        'customer' => 'cus_trial',
        'status' => 'trialing',
        'trial_end' => $trialEnd->getTimestamp(),
        'current_period_end' => $trialEnd->getTimestamp(),
        'items' => ['data' => [['price' => ['id' => 'price_pro_yearly']]]],
    ]));

    $company->refresh();

    expect($company->trial_ends_at->getTimestamp())->toBe($trialEnd->getTimestamp())
        ->and($company->plan_id)->toBe('pro')
        ->and(SubscriptionState::for($company)->isOnTrial())->toBeTrue();
});

it('ends access when a subscription is deleted', function () {
    $company = billableWithStripeId('cus_del');
    $company->forceFill(['subscription_ends_at' => Carbon::now()->addMonth()])->save();

    event(subscriptionEvent('customer.subscription.deleted', [
        'customer' => 'cus_del',
        'status' => 'canceled',
    ]));

    $company->refresh();

    expect($company->subscription_ends_at->isFuture())->toBeFalse()
        ->and(SubscriptionState::for($company)->hasActiveSubscription())->toBeFalse();
});

it('ignores webhook events for an unknown customer', function () {
    $company = billableWithStripeId('cus_known');

    event(subscriptionEvent('customer.subscription.updated', [
        'customer' => 'cus_someone_else',
        'status' => 'active',
        'current_period_end' => Carbon::now()->addMonth()->getTimestamp(),
    ]));

    $company->refresh();

    // Untouched: the event named a different customer.
    expect($company->subscription_ends_at)->toBeNull();
});

it('ignores non-subscription webhook events', function () {
    $company = billableWithStripeId('cus_invoice');

    event(new WebhookReceived([
        'type' => 'invoice.payment_succeeded',
        'data' => ['object' => ['customer' => 'cus_invoice']],
    ]));

    $company->refresh();

    expect($company->subscription_ends_at)->toBeNull()
        ->and($company->plan_id)->toBeNull();
});
