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
 * The super-admin affiliates console (Ф4.4, sub-step D): the list with referral/commission
 * counts, the super-admin gate, enrol + edit, and the throttled user search.
 */
function billingAcAdmin(): AdminUser
{
    return AdminUser::query()->create([
        'name' => 'Root', 'email' => 'ac-root@example.test', 'password' => bcrypt('secret'),
    ]);
}

function acUserId(string $slug): int
{
    return DB::table('users')->insertGetId([
        'name' => 'Partner', 'lastname' => 'Smith', 'email' => "ac+{$slug}@example.test",
        'password' => bcrypt('secret'), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function acAffiliate(string $slug, string $status = Affiliate::STATUS_ACTIVE): Affiliate
{
    return Affiliate::query()->create([
        'user_id' => acUserId($slug),
        'code' => strtoupper(Str::random(8)),
        'status' => $status,
        'approved_at' => $status === Affiliate::STATUS_ACTIVE ? now() : null,
    ]);
}

function acCommission(Affiliate $affiliate, Referral $referral, string $status, int $companyId): AffiliateCommission
{
    $payment = CompanyPayment::query()->create([
        'company_id' => $companyId, 'plan_id' => 'pro', 'billing_period' => 'monthly',
        'amount' => 2000, 'currency' => 'USD', 'payment_status' => 'success', 'paid_at' => now(),
    ]);

    return AffiliateCommission::query()->create([
        'affiliate_id' => $affiliate->getKey(),
        'referral_id' => $referral->getKey(),
        'company_payment_id' => $payment->getKey(),
        'company_id' => $companyId,
        'currency' => 'USD',
        'amount' => 500,
        'status' => $status,
        'basis' => 'per_plan',
    ]);
}

it('renders the affiliates console for a super-admin', function () {
    acAffiliate('render');

    $this->actingAs(billingAcAdmin())
        ->get('/admin/affiliates', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Admin/Affiliates/Index')
        ->assertJsonStructure(['props' => ['affiliates' => ['data'], 'pagination', 'filters', 'eligibility']]);
});

it('denies a non-admin user', function () {
    $this->actingAs(PaymentUser::query()->create([
        'name' => 'Joe', 'email' => 'ac-joe@example.test', 'password' => bcrypt('secret'),
    ]))->get('/admin/affiliates')->assertStatus(403);
});

it('filters affiliates by status', function () {
    acAffiliate('act', Affiliate::STATUS_ACTIVE);
    acAffiliate('dis', Affiliate::STATUS_DISABLED);

    $rows = collect($this->actingAs(billingAcAdmin())
        ->get('/admin/affiliates?status=disabled', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.affiliates.data'));

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['status'])->toBe('disabled');
});

it('enrols an affiliate for a user', function () {
    $userId = acUserId('enrol');

    $this->actingAs(billingAcAdmin())
        ->post('/admin/affiliates', ['user_id' => $userId, 'commission_rate_bp' => 1500])
        ->assertStatus(302);

    $affiliate = Affiliate::query()->where('user_id', $userId)->first();

    expect($affiliate)->not->toBeNull()
        ->and($affiliate->commission_rate_bp)->toBe(1500)
        ->and($affiliate->code)->not->toBe('');
});

it('rejects enrolling the same user twice', function () {
    $affiliate = acAffiliate('dup');

    $this->actingAs(billingAcAdmin())
        ->postJson('/admin/affiliates', ['user_id' => $affiliate->user_id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_id');
});

it('edits an affiliate rate and status', function () {
    $affiliate = acAffiliate('edit', Affiliate::STATUS_PENDING);

    $this->actingAs(billingAcAdmin())
        ->put("/admin/affiliates/{$affiliate->getKey()}", ['commission_rate_bp' => 800, 'status' => 'active'])
        ->assertStatus(302);

    $fresh = $affiliate->fresh();

    expect($fresh->commission_rate_bp)->toBe(800)
        ->and($fresh->status)->toBe('active')
        ->and($fresh->approved_at)->not->toBeNull();
});

it('searches users for the enrol form and respects the minimum length', function () {
    acUserId('searchable-target');
    $admin = billingAcAdmin();

    $hit = $this->actingAs($admin)
        ->getJson('/admin/affiliates/search-users?q=searchable-target')
        ->assertOk()
        ->json('data');

    expect(collect($hit)->pluck('email'))->toContain('ac+searchable-target@example.test');

    // Below the minimum query length: empty, no enumeration.
    $this->actingAs($admin)
        ->getJson('/admin/affiliates/search-users?q=a')
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('reports referral and commission counts per status in the list', function () {
    $affiliate = acAffiliate('counts');
    $referral = Referral::query()->create([
        'affiliate_id' => $affiliate->getKey(),
        'referred_company_id' => 9100,
        'source_code' => $affiliate->code,
        'attributed_at' => now(),
    ]);

    acCommission($affiliate, $referral, AffiliateCommission::STATUS_PENDING, 9100);
    acCommission($affiliate, $referral, AffiliateCommission::STATUS_APPROVED, 9101);
    acCommission($affiliate, $referral, AffiliateCommission::STATUS_PAID, 9102);

    $row = collect($this->actingAs(billingAcAdmin())
        ->get('/admin/affiliates', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.affiliates.data'))
        ->firstWhere('id', $affiliate->getKey());

    expect($row['referrals_count'])->toBe(1)
        ->and($row['pending_count'])->toBe(1)
        ->and($row['approved_count'])->toBe(1)
        ->and($row['paid_count'])->toBe(1);
});
