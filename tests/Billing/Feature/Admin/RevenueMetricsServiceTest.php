<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Dashboard\RevenueMetricsService;
use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\PaymentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Freeze time mid-month, mid-year so the window boundaries are deterministic
    // regardless of when CI runs (no January/month-end edge flakiness).
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function revCompany(string $slug, array $billing = []): BillableCompany
{
    $owner = PaymentUser::query()->create([
        'name' => 'Owner',
        'email' => "owner+{$slug}@example.test",
        'password' => bcrypt('secret'),
    ]);

    $company = new BillableCompany;
    $company->forceFill(array_merge([
        'uuid' => (string) Str::uuid(),
        'name' => 'Acme '.$slug,
        'slug' => $slug,
        'country' => 'US',
        'created_by_id' => $owner->getKey(),
    ], $billing))->save();

    return $company->refresh();
}

function revPayment(BillableCompany $company, array $attrs = []): CompanyPayment
{
    return CompanyPayment::query()->create(array_merge([
        'company_id' => $company->getKey(),
        'user_id' => $company->created_by_id,
        'plan_id' => 'pro',
        'billing_period' => CompanyPayment::PERIOD_MONTHLY,
        'amount' => 1000,
        'discount_amount' => 0,
        'currency' => 'USD',
        'payment_status' => CompanyPayment::STATUS_SUCCESS,
        'paid_at' => now(),
    ], $attrs));
}

it('totals successful payments per currency across windows without converting', function () {
    $service = app(RevenueMetricsService::class);
    $company = revCompany('rev');

    revPayment($company, ['amount' => 1000, 'currency' => 'USD', 'paid_at' => now()]);                       // this_month, year, all
    revPayment($company, ['amount' => 2000, 'currency' => 'USD', 'paid_at' => now()->subMonthNoOverflow()]); // last_month (May), year, all
    revPayment($company, ['amount' => 500, 'currency' => 'USD', 'paid_at' => now()->subYears(2)]);           // all only
    revPayment($company, ['amount' => 3000, 'currency' => 'EUR', 'paid_at' => now()]);                       // EUR this_month/year/all
    // Excluded: not successful.
    revPayment($company, ['amount' => 9999, 'currency' => 'USD', 'payment_status' => CompanyPayment::STATUS_FAILED, 'paid_at' => now()]);
    revPayment($company, ['amount' => 7777, 'currency' => 'USD', 'payment_status' => CompanyPayment::STATUS_PENDING, 'paid_at' => null]);

    $byCurrency = collect($service->summary()['currencies'])->keyBy('currency');

    expect($byCurrency['USD']['this_month'])->toBe(1000)
        ->and($byCurrency['USD']['last_month'])->toBe(2000)
        ->and($byCurrency['USD']['year'])->toBe(3000)   // May + June 2026
        ->and($byCurrency['USD']['all'])->toBe(3500)    // + the 2024 charge
        ->and($byCurrency['EUR']['this_month'])->toBe(3000)
        ->and($byCurrency['EUR']['last_month'])->toBe(0)
        ->and($byCurrency['EUR']['year'])->toBe(3000)
        ->and($byCurrency['EUR']['all'])->toBe(3000);
});

it('nets the discount out of the per-currency totals', function () {
    $service = app(RevenueMetricsService::class);
    $company = revCompany('disc');

    revPayment($company, ['amount' => 5000, 'discount_amount' => 1500, 'currency' => 'USD', 'paid_at' => now()]);

    $usd = collect($service->summary()['currencies'])->firstWhere('currency', 'USD');

    expect($usd['this_month'])->toBe(3500)
        ->and($usd['all'])->toBe(3500);
});

it('counts only companies with a live subscription, never ledger rows', function () {
    $service = app(RevenueMetricsService::class);

    revCompany('active1', ['subscription_ends_at' => now()->addDays(10)]);
    revCompany('active2', ['subscription_ends_at' => now()->addMonths(2)]);
    revCompany('expired', ['subscription_ends_at' => now()->subDays(3)]);
    $never = revCompany('never'); // no subscription column set

    // Many payment rows must NOT inflate the subscription count — it reads the
    // mirrored company column, not the ledger.
    revPayment($never, ['amount' => 5000]);
    revPayment($never, ['amount' => 6000]);

    expect($service->summary()['active_subscriptions'])->toBe(2);
});

it('returns an empty currency list and zero active subscriptions with no data', function () {
    expect(app(RevenueMetricsService::class)->summary())
        ->toMatchArray(['currencies' => [], 'active_subscriptions' => 0]);
});
