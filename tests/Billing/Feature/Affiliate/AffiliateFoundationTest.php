<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Models\Affiliate;
use Dmitryisaenko\LaraFoundry\Billing\Models\AffiliateCommission;
use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Billing\Models\Referral;
use Dmitryisaenko\LaraFoundry\Billing\Support\AffiliateCodeGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Foundation of the affiliate program (Ф4.4, sub-step A): the three tables, the lazy
 * partner identity, the unique code generator, the commission ledger transitions and
 * the per-affiliate rate maths. No attribution or accrual wiring yet (those are B/C).
 */
function affiliateUserId(string $slug = 'aff'): int
{
    return DB::table('users')->insertGetId([
        'name' => 'Partner',
        'email' => "partner+{$slug}@example.test",
        'password' => bcrypt('secret'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function affiliatePayment(int $companyId, array $attributes = []): CompanyPayment
{
    return CompanyPayment::query()->create(array_merge([
        'company_id' => $companyId,
        'plan_id' => 'pro',
        'billing_period' => CompanyPayment::PERIOD_MONTHLY,
        'amount' => 2000,
        'currency' => 'USD',
        'payment_status' => CompanyPayment::STATUS_SUCCESS,
        'payment_method' => 'stripe',
    ], $attributes));
}

it('creates the affiliate, referral and commission tables', function () {
    expect(Schema::hasTable('affiliates'))->toBeTrue()
        ->and(Schema::hasTable('referrals'))->toBeTrue()
        ->and(Schema::hasTable('affiliate_commissions'))->toBeTrue();
});

it('lazily creates one partner identity per user with a code', function () {
    $userId = affiliateUserId('lazy');

    $first = Affiliate::ensureFor($userId);

    expect($first->code)->not->toBe('')
        ->and($first->status)->toBe(Affiliate::STATUS_ACTIVE)
        ->and($first->approved_at)->not->toBeNull();

    // Idempotent: a second call returns the same row, never a duplicate.
    $second = Affiliate::ensureFor($userId);

    expect($second->getKey())->toBe($first->getKey())
        ->and(Affiliate::query()->where('user_id', $userId)->count())->toBe(1);
});

it('can create a pending partner (self-serve with approval)', function () {
    $affiliate = Affiliate::ensureFor(affiliateUserId('pending'), Affiliate::STATUS_PENDING);

    expect($affiliate->status)->toBe(Affiliate::STATUS_PENDING)
        ->and($affiliate->approved_at)->toBeNull()
        ->and($affiliate->isActive())->toBeFalse();
});

it('generates unique codes from an unambiguous alphabet', function () {
    $generator = app(AffiliateCodeGenerator::class);

    $codes = collect(range(1, 25))->map(fn () => $generator->generate());

    // Distinct, fixed length, and never the ambiguous 0/O/1/I.
    expect($codes->unique()->count())->toBe(25)
        ->and($codes->every(fn (string $c) => strlen($c) === 8))->toBeTrue()
        ->and($codes->every(fn (string $c) => preg_match('/^[A-HJ-NP-Z2-9]+$/', $c) === 1))->toBeTrue();
});

it('resolves the effective commission rate from override then config default', function () {
    config()->set('larafoundry-billing.affiliate.commission.percent.default_rate', 20);

    $default = Affiliate::ensureFor(affiliateUserId('rate-default'));
    expect($default->effectiveRateBp())->toBe(2000); // 20% => 2000 bp

    $override = Affiliate::ensureFor(affiliateUserId('rate-override'));
    $override->update(['commission_rate_bp' => 1250]);
    expect($override->effectiveRateBp())->toBe(1250); // override wins
});

it('coerces a percent number (or numeric string) to basis points, junk to zero', function () {
    expect(Affiliate::percentToBp(20))->toBe(2000)
        ->and(Affiliate::percentToBp(12.5))->toBe(1250)
        // A numeric string (an env-provided rate) must NOT silently drop to 0.
        ->and(Affiliate::percentToBp('20'))->toBe(2000)
        ->and(Affiliate::percentToBp('12.5'))->toBe(1250)
        ->and(Affiliate::percentToBp('nonsense'))->toBe(0)
        ->and(Affiliate::percentToBp(-5))->toBe(0);
});

it('bounds the recurring window on a referral', function () {
    $affiliate = Affiliate::ensureFor(affiliateUserId('window'));

    $referral = Referral::query()->create([
        'affiliate_id' => $affiliate->getKey(),
        'referred_company_id' => 4242,
        'source_code' => $affiliate->code,
        'attributed_at' => now(),
        'window_ends_at' => now()->addMonths(12),
    ]);

    expect($referral->isWithinWindow(now()->addMonths(6)))->toBeTrue()
        ->and($referral->isWithinWindow(now()->addMonths(13)))->toBeFalse();

    // An open (null) window always qualifies.
    $open = Referral::query()->create([
        'affiliate_id' => $affiliate->getKey(),
        'referred_company_id' => 4243,
        'source_code' => $affiliate->code,
        'attributed_at' => now(),
        'window_ends_at' => null,
    ]);

    expect($open->isWithinWindow(now()->addYears(10)))->toBeTrue();
});

it('transitions a commission pending -> approved -> paid and can be voided', function () {
    $affiliate = Affiliate::ensureFor(affiliateUserId('ledger'));
    $referral = Referral::query()->create([
        'affiliate_id' => $affiliate->getKey(),
        'referred_company_id' => 7001,
        'source_code' => $affiliate->code,
        'attributed_at' => now(),
    ]);
    $payment = affiliatePayment(7001);

    $commission = AffiliateCommission::query()->create([
        'affiliate_id' => $affiliate->getKey(),
        'referral_id' => $referral->getKey(),
        'company_payment_id' => $payment->getKey(),
        'company_id' => 7001,
        'currency' => 'USD',
        'amount' => 500,
        'status' => AffiliateCommission::STATUS_PENDING,
        'basis' => 'per_plan',
    ]);

    expect($commission->isPending())->toBeTrue();

    $commission->markApproved();
    expect($commission->fresh()->status)->toBe(AffiliateCommission::STATUS_APPROVED)
        ->and($commission->fresh()->approved_at)->not->toBeNull();

    $commission->markPaid('payout-2026-06');
    expect($commission->fresh()->status)->toBe(AffiliateCommission::STATUS_PAID)
        ->and($commission->fresh()->paid_at)->not->toBeNull()
        ->and($commission->fresh()->payout_reference)->toBe('payout-2026-06');

    $commission->void();
    expect($commission->fresh()->isVoid())->toBeTrue();
});

it('enforces one commission per payment at the schema level', function () {
    $affiliate = Affiliate::ensureFor(affiliateUserId('idem'));
    $referral = Referral::query()->create([
        'affiliate_id' => $affiliate->getKey(),
        'referred_company_id' => 8001,
        'source_code' => $affiliate->code,
        'attributed_at' => now(),
    ]);
    $payment = affiliatePayment(8001);

    $attributes = [
        'affiliate_id' => $affiliate->getKey(),
        'referral_id' => $referral->getKey(),
        'company_payment_id' => $payment->getKey(),
        'company_id' => 8001,
        'currency' => 'USD',
        'amount' => 500,
        'status' => AffiliateCommission::STATUS_PENDING,
        'basis' => 'per_plan',
    ];

    AffiliateCommission::query()->create($attributes);

    expect(fn () => AffiliateCommission::query()->create($attributes))
        ->toThrow(QueryException::class);
});
