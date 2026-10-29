<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Actions\GetBlockStatusAction;
use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\PaymentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * GetBlockStatusAction resolves a company's billing status on the subscription axis:
 * owner-ban cascade (before any payment check), first-payment vs expired, and the
 * expires-soon warning window. A company still in setup is never blocked.
 */
function blockStatusCompany(string $slug, array $companyAttrs = [], bool $ownerBlocked = false): BillableCompany
{
    $owner = PaymentUser::query()->create([
        'name' => 'Owner',
        'email' => "owner+{$slug}@example.test",
        'password' => bcrypt('secret'),
        'user_blocked_at' => $ownerBlocked ? now() : null,
    ]);

    $company = new BillableCompany;
    $company->forceFill(array_merge([
        'uuid' => (string) Str::uuid(),
        'name' => 'Acme',
        'slug' => $slug,
        'country' => 'US',
        'created_by_id' => $owner->getKey(),
    ], $companyAttrs))->save();

    return $company->refresh();
}

function blockStatus(BillableCompany $company): array
{
    return app(GetBlockStatusAction::class)($company);
}

it('reports no block for a company with a live subscription', function () {
    $company = blockStatusCompany('live', ['subscription_ends_at' => Carbon::now()->addMonths(2)]);

    expect(blockStatus($company))->toMatchArray([
        'company_blocked' => false,
        'company_block_reason' => null,
        'subscription_expires_soon' => false,
    ]);
});

it('flags first_payment_required with no access and no payment history', function () {
    $company = blockStatusCompany('first'); // no subscription/trial columns => no access

    $status = blockStatus($company);
    expect($status['company_blocked'])->toBeTrue()
        ->and($status['company_block_reason'])->toBe('first_payment_required');
});

it('flags payment_expired with no access but a prior payment', function () {
    $company = blockStatusCompany('expired');
    CompanyPayment::query()->create([
        'company_id' => $company->getTenantKey(),
        'plan_id' => 'pro',
        'billing_period' => CompanyPayment::PERIOD_MONTHLY,
        'amount' => 1000,
        'currency' => 'USD',
        'payment_status' => CompanyPayment::STATUS_SUCCESS,
    ]);

    expect(blockStatus($company)['company_block_reason'])->toBe('payment_expired');
});

it('flags owner_banned before any payment check when the founder is blocked', function () {
    // The subscription is live, but the banned owner still cascades.
    $company = blockStatusCompany('ob', ['subscription_ends_at' => Carbon::now()->addMonth()], ownerBlocked: true);

    $status = blockStatus($company);
    expect($status['company_blocked'])->toBeTrue()
        ->and($status['company_block_reason'])->toBe('owner_banned');
});

it('warns when the subscription expires within the window', function () {
    $company = blockStatusCompany('soon', ['subscription_ends_at' => Carbon::now()->addDays(5)]);

    $status = blockStatus($company);
    expect($status['subscription_expires_soon'])->toBeTrue()
        ->and($status['days_until_expiry'])->toBe(5)
        ->and($status['company_blocked'])->toBeFalse();
});

it('does not warn for a subscription beyond the window', function () {
    $company = blockStatusCompany('far', ['subscription_ends_at' => Carbon::now()->addDays(40)]);

    expect(blockStatus($company)['subscription_expires_soon'])->toBeFalse();
});

it('warns on the day of expiry (days = 0)', function () {
    $company = blockStatusCompany('today', ['subscription_ends_at' => Carbon::now()->endOfDay()]);

    $status = blockStatus($company);
    expect($status['subscription_expires_soon'])->toBeTrue()
        ->and($status['days_until_expiry'])->toBe(0)
        ->and($status['company_blocked'])->toBeFalse();
});

it('warns exactly at the window boundary (days = window)', function () {
    $company = blockStatusCompany('edge', ['subscription_ends_at' => Carbon::now()->addDays(10)]);

    $status = blockStatus($company);
    expect($status['subscription_expires_soon'])->toBeTrue()
        ->and($status['days_until_expiry'])->toBe(10);
});

it('returns no block for a company still in setup', function () {
    $company = blockStatusCompany('setup', ['country' => null]); // isInSetup() === true

    expect(blockStatus($company)['company_blocked'])->toBeFalse();
});
