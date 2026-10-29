<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Events\CompanyPaymentProcessed;
use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Cashier\Events\WebhookReceived;

uses(RefreshDatabase::class);

/**
 * The payment recorder writes a ledger row from a verified Stripe invoice webhook and
 * fires CompanyPaymentProcessed. It sits beside the mirror on the same event without
 * overlap (mirror = subscription.*, recorder = invoice.*), is idempotent on a
 * re-delivered webhook, and ignores events for unknown customers / other types.
 */
function billableForPayment(string $stripeId): BillableCompany
{
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

function invoiceEvent(string $type, array $invoice): WebhookReceived
{
    return new WebhookReceived([
        'type' => $type,
        'data' => ['object' => $invoice],
    ]);
}

function stripeInvoice(string $customer, array $overrides = []): array
{
    return array_merge([
        'id' => 'in_'.$customer,
        'customer' => $customer,
        'status' => 'paid',
        'currency' => 'usd',
        'amount_paid' => 2500,
        'amount_due' => 2500,
        'subscription' => 'sub_'.$customer,
        'payment_intent' => 'pi_'.$customer,
        'lines' => ['data' => [[
            'price' => ['id' => 'price_pro_monthly', 'recurring' => ['interval' => 'month']],
            'period' => [
                'start' => Carbon::now()->getTimestamp(),
                'end' => Carbon::now()->addMonth()->getTimestamp(),
            ],
        ]]],
    ], $overrides);
}

it('records a paid invoice as a successful payment and fires the event', function () {
    Event::fake([CompanyPaymentProcessed::class]);
    $company = billableForPayment('cus_paid');

    event(invoiceEvent('invoice.paid', stripeInvoice('cus_paid')));

    $payment = CompanyPayment::query()->where('company_id', $company->getKey())->first();

    expect($payment)->not->toBeNull()
        ->and($payment->payment_status)->toBe(CompanyPayment::STATUS_SUCCESS)
        ->and($payment->amount)->toBe(2500)
        ->and($payment->currency)->toBe('USD')
        ->and($payment->plan_id)->toBe('pro')
        ->and($payment->billing_period)->toBe(CompanyPayment::PERIOD_MONTHLY)
        ->and($payment->payment_method)->toBe('stripe')
        ->and($payment->gateway_reference)->toBe('in_cus_paid')
        ->and($payment->paid_at)->not->toBeNull()
        ->and($payment->user_id)->toBe($company->created_by_id)
        ->and($payment->period_end)->not->toBeNull();

    Event::assertDispatched(CompanyPaymentProcessed::class);
});

it('records a failed invoice as a failed payment with the amount due', function () {
    $company = billableForPayment('cus_fail');

    event(invoiceEvent('invoice.payment_failed', stripeInvoice('cus_fail', [
        'id' => 'in_fail',
        'status' => 'open',
        'amount_paid' => 0,
        'amount_due' => 4900,
    ])));

    $payment = CompanyPayment::query()->where('company_id', $company->getKey())->first();

    expect($payment->payment_status)->toBe(CompanyPayment::STATUS_FAILED)
        ->and($payment->amount)->toBe(4900)
        ->and($payment->paid_at)->toBeNull();
});

it('is idempotent across re-delivery of the same invoice and status', function () {
    $company = billableForPayment('cus_dup');
    $invoice = stripeInvoice('cus_dup');

    event(invoiceEvent('invoice.paid', $invoice));
    event(invoiceEvent('invoice.payment_succeeded', $invoice)); // same id + success status
    event(invoiceEvent('invoice.paid', $invoice));              // re-delivery

    expect(CompanyPayment::query()->where('company_id', $company->getKey())->count())->toBe(1);
});

it('keeps a failed and a later successful charge for the same invoice as distinct rows', function () {
    $company = billableForPayment('cus_retry');

    // A first attempt fails, then the SAME invoice is paid on retry. Idempotency keys on
    // (gateway_reference, payment_status), so the transition is two rows — a failed one and
    // a successful one — never collapsed into one and never a duplicate success.
    event(invoiceEvent('invoice.payment_failed', stripeInvoice('cus_retry', [
        'id' => 'in_retry',
        'status' => 'open',
        'amount_paid' => 0,
        'amount_due' => 4900,
    ])));
    event(invoiceEvent('invoice.paid', stripeInvoice('cus_retry', ['id' => 'in_retry'])));

    $payments = CompanyPayment::query()->where('company_id', $company->getKey())->get();

    expect($payments)->toHaveCount(2)
        ->and($payments->pluck('payment_status')->sort()->values()->all())
        ->toBe([CompanyPayment::STATUS_FAILED, CompanyPayment::STATUS_SUCCESS]);
});

it('ignores an invoice for an unknown customer', function () {
    billableForPayment('cus_known');

    event(invoiceEvent('invoice.paid', stripeInvoice('cus_stranger')));

    expect(CompanyPayment::query()->count())->toBe(0);
});

it('ignores webhook types that are not invoice payments', function () {
    $company = billableForPayment('cus_other');

    event(invoiceEvent('charge.refunded', ['id' => 'ch_1', 'customer' => 'cus_other']));

    expect(CompanyPayment::query()->where('company_id', $company->getKey())->count())->toBe(0);
});
