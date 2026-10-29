<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Models\Affiliate;
use Dmitryisaenko\LaraFoundry\Billing\Models\AffiliateCommission;
use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Billing\Models\Referral;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\AdminUser;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\PaymentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The super-admin payout console (Ф4.4, sub-step D): per-currency totals by status (no
 * conversion), the bulk approve/mark-paid/void state machine, the CSV export, and the
 * super-admin gate.
 */
function poAdmin(): AdminUser
{
    return AdminUser::query()->create([
        'name' => 'Root', 'email' => 'po-root@example.test', 'password' => bcrypt('secret'),
    ]);
}

function poAffiliate(string $slug): Affiliate
{
    $userId = DB::table('users')->insertGetId([
        'name' => 'P', 'lastname' => 'O', 'email' => "po+{$slug}@example.test",
        'password' => bcrypt('secret'), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return Affiliate::query()->create([
        'user_id' => $userId, 'code' => strtoupper(Str::random(8)),
        'status' => Affiliate::STATUS_ACTIVE, 'approved_at' => now(),
    ]);
}

function poCommission(Affiliate $affiliate, int $companyId, string $status, int $amount, string $currency = 'USD'): AffiliateCommission
{
    $referral = Referral::query()->firstOrCreate(
        ['referred_company_id' => $companyId],
        ['affiliate_id' => $affiliate->getKey(), 'source_code' => $affiliate->code, 'attributed_at' => now()],
    );

    $payment = CompanyPayment::query()->create([
        'company_id' => $companyId, 'plan_id' => 'pro', 'billing_period' => 'monthly',
        'amount' => 2000, 'currency' => $currency, 'payment_status' => 'success', 'paid_at' => now(),
    ]);

    return AffiliateCommission::query()->create([
        'affiliate_id' => $affiliate->getKey(),
        'referral_id' => $referral->getKey(),
        'company_payment_id' => $payment->getKey(),
        'company_id' => $companyId,
        'currency' => $currency,
        'amount' => $amount,
        'status' => $status,
        'basis' => 'per_plan',
    ]);
}

it('renders the payout report for a super-admin', function () {
    poCommission(poAffiliate('render'), 9200, AffiliateCommission::STATUS_PENDING, 500);

    $this->actingAs(poAdmin())
        ->get('/admin/payouts', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Admin/Payouts/Index')
        ->assertJsonStructure(['props' => ['commissions' => ['data'], 'totals', 'pagination', 'filters', 'available_affiliates']]);
});

it('denies a non-admin user', function () {
    $this->actingAs(PaymentUser::query()->create([
        'name' => 'Joe', 'email' => 'po-joe@example.test', 'password' => bcrypt('secret'),
    ]))->get('/admin/payouts')->assertStatus(403);
});

it('reports totals per currency and status without conversion', function () {
    $affiliate = poAffiliate('totals');
    poCommission($affiliate, 9300, AffiliateCommission::STATUS_PENDING, 500, 'USD');
    poCommission($affiliate, 9301, AffiliateCommission::STATUS_PENDING, 300, 'USD');
    poCommission($affiliate, 9302, AffiliateCommission::STATUS_PENDING, 700, 'EUR');
    poCommission($affiliate, 9303, AffiliateCommission::STATUS_PAID, 900, 'USD');

    $totals = collect($this->actingAs(poAdmin())
        ->get('/admin/payouts', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.totals'));

    $usdPending = $totals->first(fn ($t) => $t['currency'] === 'USD' && $t['status'] === 'pending');
    $eurPending = $totals->first(fn ($t) => $t['currency'] === 'EUR' && $t['status'] === 'pending');
    $usdPaid = $totals->first(fn ($t) => $t['currency'] === 'USD' && $t['status'] === 'paid');

    expect($usdPending['total'])->toBe(800)
        ->and($usdPending['count'])->toBe(2)
        ->and($eurPending['total'])->toBe(700)
        ->and($usdPaid['total'])->toBe(900);
});

it('approves only pending commissions in the selection', function () {
    $affiliate = poAffiliate('approve');
    $pending = poCommission($affiliate, 9400, AffiliateCommission::STATUS_PENDING, 500);
    $paid = poCommission($affiliate, 9401, AffiliateCommission::STATUS_PAID, 500);

    $this->actingAs(poAdmin())
        ->post('/admin/payouts/approve', ['ids' => [$pending->getKey(), $paid->getKey()]])
        ->assertStatus(302);

    expect($pending->fresh()->status)->toBe(AffiliateCommission::STATUS_APPROVED)
        ->and($pending->fresh()->approved_at)->not->toBeNull()
        ->and($paid->fresh()->status)->toBe(AffiliateCommission::STATUS_PAID); // untouched
});

it('marks only approved commissions paid, carrying the reference', function () {
    $affiliate = poAffiliate('paid');
    $approved = poCommission($affiliate, 9500, AffiliateCommission::STATUS_APPROVED, 500);
    $pending = poCommission($affiliate, 9501, AffiliateCommission::STATUS_PENDING, 500);

    $this->actingAs(poAdmin())
        ->post('/admin/payouts/mark-paid', ['ids' => [$approved->getKey(), $pending->getKey()], 'reference' => 'wire-2026-06'])
        ->assertStatus(302);

    expect($approved->fresh()->status)->toBe(AffiliateCommission::STATUS_PAID)
        ->and($approved->fresh()->payout_reference)->toBe('wire-2026-06')
        ->and($pending->fresh()->status)->toBe(AffiliateCommission::STATUS_PENDING); // not yet approved
});

it('voids pending or approved commissions (manual clawback)', function () {
    $affiliate = poAffiliate('void');
    $pending = poCommission($affiliate, 9600, AffiliateCommission::STATUS_PENDING, 500);
    $paid = poCommission($affiliate, 9601, AffiliateCommission::STATUS_PAID, 500);

    $this->actingAs(poAdmin())
        ->post('/admin/payouts/void', ['ids' => [$pending->getKey(), $paid->getKey()]])
        ->assertStatus(302);

    expect($pending->fresh()->status)->toBe(AffiliateCommission::STATUS_VOID)
        ->and($paid->fresh()->status)->toBe(AffiliateCommission::STATUS_PAID); // paid is never auto-voided
});

it('filters the report by status', function () {
    $affiliate = poAffiliate('filter');
    poCommission($affiliate, 9700, AffiliateCommission::STATUS_PENDING, 500);
    poCommission($affiliate, 9701, AffiliateCommission::STATUS_PAID, 500);

    $rows = collect($this->actingAs(poAdmin())
        ->get('/admin/payouts?status=paid', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.commissions.data'));

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['status'])->toBe('paid');
});

it('exports the filtered report as CSV', function () {
    $affiliate = poAffiliate('csv');
    poCommission($affiliate, 9800, AffiliateCommission::STATUS_APPROVED, 1234, 'USD');

    $response = $this->actingAs(poAdmin())->get('/admin/payouts/export')->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/csv');

    $csv = $response->streamedContent();

    expect($csv)->toContain('amount_minor')
        ->and($csv)->toContain('1234')
        ->and($csv)->toContain($affiliate->code);
});

it('neutralises spreadsheet formula injection in the CSV export', function () {
    $affiliate = poAffiliate('csv-inj');
    poCommission($affiliate, 9810, AffiliateCommission::STATUS_PAID, 500)
        ->update(['payout_reference' => '=HYPERLINK("http://evil")']);

    $csv = $this->actingAs(poAdmin())->get('/admin/payouts/export')->assertOk()->streamedContent();

    // The dangerous leading "=" is prefixed with an apostrophe (literal text, not a formula).
    expect($csv)->toContain("'=HYPERLINK");
});
