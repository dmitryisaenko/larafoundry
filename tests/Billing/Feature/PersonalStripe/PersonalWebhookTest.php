<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Events\WebhookReceived;

uses(RefreshDatabase::class);

/**
 * The verified-webhook path with a person as the payer (`personal` mode).
 *
 * Same two listeners as the teams suite, and they must land on the USER: the mirror
 * writes the access columns onto the `users` row (the add-on migration put them there),
 * and the recorder writes a ledger line whose tenant key AND payer are the same person.
 */
function personalBillable(string $stripeId): BillableUser
{
    $user = BillableUser::query()->create([
        'name' => 'Person',
        'email' => "person+{$stripeId}@example.test",
        'password' => bcrypt('secret'),
        'country' => 'UA',
    ]);

    $user->forceFill(['stripe_id' => $stripeId])->save();

    return $user->refresh();
}

it('mirrors subscription state onto the users row', function () {
    $user = personalBillable('cus_person');
    $periodEnd = Carbon::now()->addMonth()->startOfSecond();

    event(new WebhookReceived([
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'customer' => 'cus_person',
            'status' => 'active',
            'current_period_end' => $periodEnd->getTimestamp(),
            'items' => ['data' => [['price' => ['id' => 'price_pro_monthly']]]],
        ]],
    ]));

    $user->refresh();

    expect(Carbon::parse($user->subscription_ends_at)->getTimestamp())->toBe($periodEnd->getTimestamp())
        ->and($user->plan_id)->toBe('pro')
        ->and($user->hasAccess())->toBeTrue();
});

it('records a paid invoice against the person as both tenant and payer', function () {
    $user = personalBillable('cus_invoice');

    event(new WebhookReceived([
        'type' => 'invoice.paid',
        'data' => ['object' => [
            'id' => 'in_person_1',
            'customer' => 'cus_invoice',
            'currency' => 'usd',
            'amount_paid' => 1900,
            'status' => 'paid',
            'lines' => ['data' => [[
                'price' => ['id' => 'price_pro_monthly', 'recurring' => ['interval' => 'month']],
                'period' => ['start' => Carbon::now()->getTimestamp(), 'end' => Carbon::now()->addMonth()->getTimestamp()],
            ]]],
        ]],
    ]));

    $payment = CompanyPayment::query()->firstOrFail();

    expect((int) $payment->company_id)->toBe($user->getKey())
        ->and((int) $payment->user_id)->toBe($user->getKey())
        ->and($payment->plan_id)->toBe('pro')
        ->and($payment->amount)->toBe(1900)
        ->and($payment->payment_status)->toBe(CompanyPayment::STATUS_SUCCESS)
        // The relation resolves the configured billable — the person, not a company.
        ->and($payment->company?->getKey())->toBe($user->getKey());
});
