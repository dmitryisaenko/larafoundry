<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Events\CompanyPaymentProcessed;
use Dmitryisaenko\LaraFoundry\Billing\Listeners\AccrueAffiliateCommission;
use Dmitryisaenko\LaraFoundry\Billing\Models\Affiliate;
use Dmitryisaenko\LaraFoundry\Billing\Models\AffiliateCommission;
use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Billing\Models\Referral;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The commission engine (Ф4.4, sub-step C): the two orthogonal axes (trigger × basis),
 * idempotent accrual, same-invoice clawback, and fail-closed handling of an unreferred
 * company / unknown plan / inactive partner.
 */
function commUser(string $slug): int
{
    return DB::table('users')->insertGetId([
        'name' => 'U',
        'email' => "c+{$slug}@example.test",
        'password' => bcrypt('secret'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function commCompany(string $slug, int $ownerId): BillableCompany
{
    $company = new BillableCompany;
    $company->forceFill([
        'uuid' => (string) Str::uuid(),
        'name' => 'Co '.$slug,
        'slug' => $slug,
        'country' => 'US',
        'created_by_id' => $ownerId,
    ])->save();

    return $company->refresh();
}

/**
 * @return array{0: Affiliate, 1: Referral, 2: BillableCompany}
 */
function referredCompany(string $slug, ?Carbon $window = null): array
{
    $affiliate = Affiliate::ensureFor(commUser($slug.'-p'));
    $company = commCompany($slug, commUser($slug.'-o'));
    $referral = Referral::query()->create([
        'affiliate_id' => $affiliate->getKey(),
        'referred_company_id' => $company->getKey(),
        'source_code' => $affiliate->code,
        'attributed_at' => now(),
        'window_ends_at' => $window,
    ]);

    return [$affiliate, $referral, $company];
}

function commPayment(int $companyId, array $attributes = []): CompanyPayment
{
    return CompanyPayment::query()->create(array_merge([
        'company_id' => $companyId,
        'plan_id' => 'pro',
        'billing_period' => CompanyPayment::PERIOD_MONTHLY,
        'amount' => 2000,
        'currency' => 'USD',
        'discount_amount' => 500,
        'payment_status' => CompanyPayment::STATUS_SUCCESS,
        'payment_method' => 'stripe',
        'paid_at' => now(),
    ], $attributes));
}

function accrue(CompanyPayment $payment): void
{
    app(AccrueAffiliateCommission::class)->handle(new CompanyPaymentProcessed($payment));
}

it('accrues a per-plan commission in the payment currency (default basis)', function () {
    [$affiliate, $referral, $company] = referredCompany('perplan');

    accrue(commPayment($company->getKey(), ['plan_id' => 'pro', 'currency' => 'USD']));

    $commission = AffiliateCommission::query()->first();

    expect($commission)->not->toBeNull()
        ->and($commission->amount)->toBe(500)          // per_plan.pro.USD
        ->and($commission->currency)->toBe('USD')
        ->and($commission->basis)->toBe('per_plan')
        ->and($commission->status)->toBe(AffiliateCommission::STATUS_PENDING)
        ->and($commission->affiliate_id)->toBe($affiliate->getKey())
        ->and($commission->referral_id)->toBe($referral->getKey());
});

it('uses the per-currency per-plan amount without conversion', function () {
    [, , $company] = referredCompany('perplan-uah');

    accrue(commPayment($company->getKey(), ['plan_id' => 'pro', 'currency' => 'UAH', 'amount' => 79000, 'discount_amount' => 0]));

    expect(AffiliateCommission::query()->value('amount'))->toBe(20000); // per_plan.pro.UAH
});

it('accrues a percentage of the net charge on the percent basis', function () {
    config()->set('larafoundry-billing.affiliate.commission.basis', 'percent');
    config()->set('larafoundry-billing.affiliate.commission.percent.default_rate', 20);

    [, , $company] = referredCompany('percent');

    accrue(commPayment($company->getKey(), ['amount' => 2000, 'discount_amount' => 500])); // net 1500

    $commission = AffiliateCommission::query()->first();

    expect($commission->amount)->toBe(300)       // 20% of 1500
        ->and($commission->basis)->toBe('percent')
        ->and($commission->rate_bp)->toBe(2000);
});

it('honours a per-affiliate percent override', function () {
    config()->set('larafoundry-billing.affiliate.commission.basis', 'percent');
    config()->set('larafoundry-billing.affiliate.commission.percent.default_rate', 20);

    [$affiliate, , $company] = referredCompany('percent-ov');
    $affiliate->update(['commission_rate_bp' => 1000]); // 10%

    accrue(commPayment($company->getKey(), ['amount' => 2000, 'discount_amount' => 500])); // net 1500

    expect(AffiliateCommission::query()->value('amount'))->toBe(150); // 10% of 1500
});

it('accrues a flat amount per currency on the fixed basis', function () {
    config()->set('larafoundry-billing.affiliate.commission.basis', 'fixed');
    config()->set('larafoundry-billing.affiliate.commission.fixed', ['USD' => 1000]);

    [, , $company] = referredCompany('fixed');

    accrue(commPayment($company->getKey(), ['currency' => 'USD']));

    expect(AffiliateCommission::query()->value('amount'))->toBe(1000);
});

it('rewards only the first successful payment under the first_payment trigger', function () {
    config()->set('larafoundry-billing.affiliate.commission.trigger', 'first_payment');

    [, , $company] = referredCompany('first');

    accrue(commPayment($company->getKey(), ['paid_at' => now()->subDay()]));
    accrue(commPayment($company->getKey(), ['paid_at' => now()]));

    expect(AffiliateCommission::query()->count())->toBe(1);
});

it('awards one first_payment bounty per referral even when payments arrive out of order', function () {
    config()->set('larafoundry-billing.affiliate.commission.trigger', 'first_payment');

    [, , $company] = referredCompany('ooo');

    // The chronologically LATER payment is processed FIRST (a webhook retry / replay).
    accrue(commPayment($company->getKey(), ['paid_at' => now()]));
    accrue(commPayment($company->getKey(), ['paid_at' => now()->subMonth()]));

    expect(AffiliateCommission::query()->where('company_id', $company->getKey())->count())->toBe(1);
});

it('re-opens a first_payment referral after its bounty is clawed back', function () {
    config()->set('larafoundry-billing.affiliate.commission.trigger', 'first_payment');

    [, , $company] = referredCompany('reopen');

    accrue(commPayment($company->getKey(), ['gateway_reference' => 'inv_reopen']));
    accrue(commPayment($company->getKey(), [
        'gateway_reference' => 'inv_reopen', 'payment_status' => CompanyPayment::STATUS_FAILED, 'paid_at' => null,
    ]));

    expect(AffiliateCommission::query()->where('company_id', $company->getKey())->where('status', AffiliateCommission::STATUS_VOID)->count())->toBe(1);

    // A genuine later payment earns a fresh bounty (the voided one no longer blocks it).
    accrue(commPayment($company->getKey(), ['paid_at' => now()]));

    expect(AffiliateCommission::query()->where('company_id', $company->getKey())->where('status', AffiliateCommission::STATUS_PENDING)->count())->toBe(1);
});

it('accrues a percent commission when the default rate is a numeric string (env value)', function () {
    config()->set('larafoundry-billing.affiliate.commission.basis', 'percent');
    config()->set('larafoundry-billing.affiliate.commission.percent.default_rate', '20'); // as env() returns it

    [, , $company] = referredCompany('percent-string');

    accrue(commPayment($company->getKey(), ['amount' => 2000, 'discount_amount' => 500])); // net 1500

    expect(AffiliateCommission::query()->value('amount'))->toBe(300); // 20% of 1500, not 0
});

it('rewards every payment within the window under the recurring trigger, none outside', function () {
    config()->set('larafoundry-billing.affiliate.commission.trigger', 'recurring');

    [, , $inWindow] = referredCompany('rec-in', now()->addMonths(12));
    accrue(commPayment($inWindow->getKey()));
    expect(AffiliateCommission::query()->where('company_id', $inWindow->getKey())->count())->toBe(1);

    [, , $expired] = referredCompany('rec-out', now()->subDay());
    accrue(commPayment($expired->getKey(), ['paid_at' => now()]));
    expect(AffiliateCommission::query()->where('company_id', $expired->getKey())->count())->toBe(0);
});

it('rewards every payment forever under the lifetime trigger', function () {
    config()->set('larafoundry-billing.affiliate.commission.trigger', 'lifetime');

    [, , $company] = referredCompany('life'); // null window

    accrue(commPayment($company->getKey(), ['paid_at' => now()->subYears(5)]));
    accrue(commPayment($company->getKey(), ['paid_at' => now()]));

    expect(AffiliateCommission::query()->where('company_id', $company->getKey())->count())->toBe(2);
});

it('is idempotent: one commission per payment even on a re-delivered event', function () {
    [, , $company] = referredCompany('idem');
    $payment = commPayment($company->getKey());

    accrue($payment);
    accrue($payment);

    expect(AffiliateCommission::query()->where('company_payment_id', $payment->getKey())->count())->toBe(1);
});

it('claws back the commission when the same invoice later fails', function () {
    [, , $company] = referredCompany('clawback');

    $success = commPayment($company->getKey(), ['gateway_reference' => 'inv_clawback']);
    accrue($success);

    $commission = AffiliateCommission::query()->where('company_payment_id', $success->getKey())->firstOrFail();
    expect($commission->status)->toBe(AffiliateCommission::STATUS_PENDING);

    $failed = commPayment($company->getKey(), [
        'gateway_reference' => 'inv_clawback',
        'payment_status' => CompanyPayment::STATUS_FAILED,
        'paid_at' => null,
    ]);
    accrue($failed);

    expect($commission->fresh()->status)->toBe(AffiliateCommission::STATUS_VOID);
});

it('does not claw back a commission that was already paid out', function () {
    [, , $company] = referredCompany('clawback-paid');

    $success = commPayment($company->getKey(), ['gateway_reference' => 'inv_paid']);
    accrue($success);
    AffiliateCommission::query()->where('company_payment_id', $success->getKey())->firstOrFail()->markPaid('po-1');

    accrue(commPayment($company->getKey(), [
        'gateway_reference' => 'inv_paid',
        'payment_status' => CompanyPayment::STATUS_FAILED,
        'paid_at' => null,
    ]));

    expect(AffiliateCommission::query()->where('company_payment_id', $success->getKey())->value('status'))
        ->toBe(AffiliateCommission::STATUS_PAID);
});

it('accrues nothing for an unreferred company, an unknown plan, or an inactive partner', function () {
    // Unreferred company.
    $orphan = commCompany('orphan', commUser('orphan-o'));
    accrue(commPayment($orphan->getKey()));
    expect(AffiliateCommission::query()->where('company_id', $orphan->getKey())->count())->toBe(0);

    // Referred, but an unknown plan for the per_plan basis (fail-closed).
    [, , $unknownPlan] = referredCompany('unknown-plan');
    accrue(commPayment($unknownPlan->getKey(), ['plan_id' => 'enterprise']));
    expect(AffiliateCommission::query()->where('company_id', $unknownPlan->getKey())->count())->toBe(0);

    // Referred, but the partner is disabled.
    [$affiliate, , $disabled] = referredCompany('disabled-aff');
    $affiliate->update(['status' => Affiliate::STATUS_DISABLED]);
    accrue(commPayment($disabled->getKey()));
    expect(AffiliateCommission::query()->where('company_id', $disabled->getKey())->count())->toBe(0);
});

it('wires the accrual listener onto CompanyPaymentProcessed and accrues from a real event', function () {
    [, , $company] = referredCompany('wired');

    event(new CompanyPaymentProcessed(commPayment($company->getKey())));

    expect(AffiliateCommission::query()->where('company_id', $company->getKey())->count())->toBe(1);
});
