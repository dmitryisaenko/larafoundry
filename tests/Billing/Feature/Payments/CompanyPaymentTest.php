<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The payment ledger model (sub-phase B7a): money is whole minor units (cents), the
 * net total never goes negative, status transitions through markAsPaid/markAsFailed,
 * and — critically for a payment log — PII is redacted from `payment_response` before
 * it is ever written to disk.
 */
function paymentCompany(string $slug = 'pay'): BillableCompany
{
    $userId = DB::table('users')->insertGetId([
        'name' => 'Owner',
        'email' => "owner+{$slug}@example.test",
        'password' => bcrypt('secret'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $company = new BillableCompany;
    $company->forceFill([
        'uuid' => (string) Str::uuid(),
        'name' => 'Acme',
        'slug' => $slug,
        'country' => 'US',
        'created_by_id' => $userId,
    ])->save();

    return $company->refresh();
}

function makePayment(BillableCompany $company, array $attributes = []): CompanyPayment
{
    return CompanyPayment::query()->create(array_merge([
        'company_id' => $company->getTenantKey(),
        'user_id' => $company->created_by_id,
        'plan_id' => 'pro',
        'billing_period' => CompanyPayment::PERIOD_MONTHLY,
        'amount' => 2000,
        'currency' => 'USD',
        'discount_amount' => 500,
        'payment_status' => CompanyPayment::STATUS_PENDING,
        'payment_method' => 'stripe',
    ], $attributes));
}

it('stores money as whole minor units (cents)', function () {
    $company = paymentCompany('cents');
    $payment = makePayment($company, ['amount' => 2999, 'discount_amount' => 0]);

    $fresh = $payment->fresh();

    expect($fresh->amount)->toBe(2999)
        ->and($fresh->amount)->toBeInt()
        ->and($fresh->discount_amount)->toBe(0);
});

it('computes the net total after discount, never negative', function () {
    $company = paymentCompany('net');

    expect(makePayment($company, ['amount' => 2000, 'discount_amount' => 500])->getTotalAmount())->toBe(1500)
        ->and(makePayment($company, ['amount' => 1000, 'discount_amount' => 4000])->getTotalAmount())->toBe(0);
});

it('transitions status via markAsPaid and markAsFailed', function () {
    $company = paymentCompany('status');
    $payment = makePayment($company);

    expect($payment->isPending())->toBeTrue();

    $payment->markAsPaid(['id' => 'ch_123', 'status' => 'succeeded']);

    expect($payment->isSuccessful())->toBeTrue()
        ->and($payment->paid_at)->not->toBeNull();

    $payment->markAsFailed(['id' => 'ch_456']);

    expect($payment->fresh()->isFailed())->toBeTrue();
});

it('keeps only an allowlisted safe subset of payment_response, dropping PII at every depth', function () {
    $company = paymentCompany('pii');
    $payment = makePayment($company);

    $payment->markAsPaid([
        'id' => 'ch_safe',
        'object' => 'charge',
        'amount' => 2000,
        'currency' => 'usd',
        'number' => '4242424242424242',            // card PAN — not allowlisted
        'cvc' => '123',                             // not allowlisted
        'receipt_email' => 'leak@example.test',     // a denylist on exact "email" would MISS this
        'billing_details' => [                      // whole container dropped
            'name' => 'Jane Doe',
            'email' => 'leak2@example.test',
            'address' => ['line1' => '1 Secret St'],
        ],
        'payment_method_details' => [               // container dropped → fingerprint never stored
            'card' => ['last4' => '4242', 'fingerprint' => 'fp_leak', 'brand' => 'visa'],
        ],
        'price' => [                                // allowlisted container, recursed
            'id' => 'price_123',                    // allowlisted child kept
            'nickname' => 'Pro Plan',               // not allowlisted, dropped
            'last4' => '9999',                      // allowlisted child kept
        ],
    ]);

    $stored = $payment->fresh()->payment_response;

    // Allowlisted scalars survive untouched.
    expect($stored['id'])->toBe('ch_safe')
        ->and($stored['object'])->toBe('charge')
        ->and($stored['amount'])->toBe(2000)
        ->and($stored['currency'])->toBe('usd');

    // Non-allowlisted keys (PII and unknown) are dropped entirely — not marked, absent.
    expect($stored)->not->toHaveKey('number')
        ->and($stored)->not->toHaveKey('cvc')
        ->and($stored)->not->toHaveKey('receipt_email')
        ->and($stored)->not->toHaveKey('billing_details')
        ->and($stored)->not->toHaveKey('payment_method_details');

    // An allowlisted container is recursed: allowlisted children survive, others drop.
    expect($stored['price'])->toBe(['id' => 'price_123', 'last4' => '9999']);

    // Nothing sensitive reaches the raw column on disk.
    $raw = DB::table('company_payments')->where('id', $payment->getKey())->value('payment_response');

    expect($raw)->not->toContain('4242424242424242')
        ->and($raw)->not->toContain('leak@example.test')
        ->and($raw)->not->toContain('leak2@example.test')
        ->and($raw)->not->toContain('Jane Doe')
        ->and($raw)->not->toContain('fp_leak')
        ->and($raw)->not->toContain('1 Secret St');
});

it('drops payload nesting deeper than the depth bound', function () {
    $company = paymentCompany('depth');
    $payment = makePayment($company);

    // Wrap an allowlisted-keyed leaf far deeper than MAX_RESPONSE_DEPTH (12), through
    // the allowlisted `price` key so recursion would otherwise proceed all the way.
    $node = ['id' => 'fp_deep_leak'];
    for ($i = 0; $i < 15; $i++) {
        $node = ['price' => $node];
    }

    $payment->markAsPaid(['id' => 'ch', 'price' => $node]);

    $raw = DB::table('company_payments')->where('id', $payment->getKey())->value('payment_response');

    expect($raw)->not->toContain('fp_deep_leak');
});

it('returns null for an absent payment_response without decoding', function () {
    $company = paymentCompany('null-resp');
    $payment = makePayment($company);

    expect($payment->fresh()->payment_response)->toBeNull();
});
