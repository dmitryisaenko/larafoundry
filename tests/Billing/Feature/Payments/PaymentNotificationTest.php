<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Events\CompanyPaymentProcessed;
use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Billing\Notifications\PaymentFailedNotification;
use Dmitryisaenko\LaraFoundry\Billing\Notifications\PaymentSucceededNotification;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\PaymentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * On CompanyPaymentProcessed the add-on emails the company owners the matching
 * success/failure notice — explicit pivot owners, else the founder — and stays silent
 * when notifications are disabled. The mail carries the formatted minor-units amount.
 */
function companyWithOwner(string $slug): BillableCompany
{
    $owner = PaymentUser::query()->create([
        'name' => 'Owner',
        'email' => "owner+{$slug}@example.test",
        'password' => bcrypt('secret'),
    ]);

    $company = new BillableCompany;
    $company->forceFill([
        'uuid' => (string) Str::uuid(),
        'name' => 'Acme',
        'slug' => $slug,
        'country' => 'US',
        'created_by_id' => $owner->getKey(),
    ])->save();

    return $company->refresh();
}

function paymentFor(BillableCompany $company, string $status): CompanyPayment
{
    return CompanyPayment::query()->create([
        'company_id' => $company->getTenantKey(),
        'user_id' => $company->created_by_id,
        'plan_id' => 'pro',
        'billing_period' => CompanyPayment::PERIOD_MONTHLY,
        'amount' => 2500,
        'currency' => 'USD',
        'payment_status' => $status,
        'payment_method' => 'stripe',
    ]);
}

it('emails the founder a success notice for a successful payment', function () {
    Notification::fake();
    $company = companyWithOwner('notify-ok');
    $payment = paymentFor($company, CompanyPayment::STATUS_SUCCESS);

    event(new CompanyPaymentProcessed($payment));

    $founder = $company->createdBy()->first();
    Notification::assertSentTo($founder, PaymentSucceededNotification::class);
    Notification::assertNotSentTo($founder, PaymentFailedNotification::class);
});

it('emails a failure notice for a failed payment', function () {
    Notification::fake();
    $company = companyWithOwner('notify-fail');
    $payment = paymentFor($company, CompanyPayment::STATUS_FAILED);

    event(new CompanyPaymentProcessed($payment));

    Notification::assertSentTo($company->createdBy()->first(), PaymentFailedNotification::class);
});

it('notifies only the founder, not other users', function () {
    Notification::fake();
    $company = companyWithOwner('notify-target');

    $stranger = PaymentUser::query()->create([
        'name' => 'Stranger',
        'email' => 'stranger+notify-target@example.test',
        'password' => bcrypt('secret'),
    ]);

    event(new CompanyPaymentProcessed(paymentFor($company, CompanyPayment::STATUS_SUCCESS)));

    Notification::assertSentTo($company->createdBy()->first(), PaymentSucceededNotification::class);
    Notification::assertNotSentTo($stranger, PaymentSucceededNotification::class);
});

it('sends nothing when notifications are disabled', function () {
    Notification::fake();
    config(['larafoundry-billing.notifications.enabled' => false]);
    $company = companyWithOwner('notify-off');

    event(new CompanyPaymentProcessed(paymentFor($company, CompanyPayment::STATUS_SUCCESS)));

    Notification::assertNothingSent();
});

it('serializes the payment by reference and re-fetches on unserialize (cron-polish)', function () {
    $company = companyWithOwner('notify-serial');
    $payment = paymentFor($company, CompanyPayment::STATUS_SUCCESS);

    $revived = unserialize(serialize(new PaymentSucceededNotification($payment)));

    // SerializesModels stored the id + class, not the whole model snapshot, and the row
    // is re-hydrated from the database here.
    expect($revived->payment->getKey())->toBe($payment->getKey())
        ->and($revived->payment->amount)->toBe(2500);
});

it('renders the success mail with subject and the formatted amount', function () {
    $company = companyWithOwner('mail-ok');
    $payment = paymentFor($company, CompanyPayment::STATUS_SUCCESS);

    $mail = (new PaymentSucceededNotification($payment))->toMail($company->createdBy()->first());

    expect($mail->subject)->toBe('Payment received')
        ->and(collect($mail->introLines)->implode(' '))->toContain('25.00 USD')
        ->and(collect($mail->introLines)->implode(' '))->toContain('Acme');
});
