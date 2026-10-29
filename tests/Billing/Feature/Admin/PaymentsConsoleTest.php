<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\AdminUser;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\PaymentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The super-admin payments console (B7b): a filtered, paginated, READ-ONLY list with
 * per-currency totals (no conversion), the allowlisted gateway response for support, and
 * the super-admin gate on every request.
 */
function consoleAdmin(): AdminUser
{
    return AdminUser::query()->create([
        'name' => 'Root',
        'email' => 'root@example.test',
        'password' => bcrypt('secret'),
    ]);
}

function consoleCompany(string $slug, array $attrs = []): BillableCompany
{
    $owner = PaymentUser::query()->create([
        'name' => 'Owner',
        'lastname' => 'McOwner',
        'country' => 'US',
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
    ], $attrs))->save();

    return $company->refresh();
}

function consolePayment(BillableCompany $company, array $attrs = []): CompanyPayment
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
        'payment_method' => 'stripe',
        'paid_at' => now(),
    ], $attrs));
}

it('renders the payments console for a super-admin', function () {
    $company = consoleCompany('idx');
    consolePayment($company);

    $this->actingAs(consoleAdmin())
        ->get('/admin/payments', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Admin/Payments/Index')
        ->assertJsonStructure(['props' => ['payments' => ['data'], 'totals', 'pagination', 'filters', 'available_plans']]);
});

it('labels the payer column by tenancy mode', function () {
    // The console must not print "Company" over a list of people in a B2C product; the
    // mode is server state, so the label ships as a prop rather than a client guess.
    $admin = consoleAdmin();

    $this->actingAs($admin)
        ->get('/admin/payments', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.subject_labels.title', 'Company')
        ->assertJsonPath('props.subject_labels.name', 'Company name');

    config(['larafoundry.tenancy.mode' => 'personal']);

    $this->actingAs($admin)
        ->get('/admin/payments', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.subject_labels.title', 'Customer')
        ->assertJsonPath('props.subject_labels.name', 'Customer name');
});

it('denies a non-admin user', function () {
    $this->actingAs(PaymentUser::query()->create([
        'name' => 'Joe', 'email' => 'joe@example.test', 'password' => bcrypt('secret'),
    ]))->get('/admin/payments')->assertStatus(403);
});

it('reports totals per currency without converting them', function () {
    $company = consoleCompany('totals');
    consolePayment($company, ['amount' => 1000, 'currency' => 'USD']);
    consolePayment($company, ['amount' => 2000, 'currency' => 'USD']);
    consolePayment($company, ['amount' => 3000, 'currency' => 'EUR']);
    consolePayment($company, ['amount' => 5000, 'currency' => 'USD', 'payment_status' => CompanyPayment::STATUS_FAILED, 'paid_at' => null]);

    $response = $this->actingAs(consoleAdmin())->get('/admin/payments', ['X-Inertia' => 'true'])->assertOk();

    $totals = collect($response->json('props.totals'));

    // Default (no status filter) shows SUCCESS totals, one row per currency, raw minor units.
    expect($totals->firstWhere('currency', 'USD')['total'])->toBe(3000)
        ->and($totals->firstWhere('currency', 'EUR')['total'])->toBe(3000)
        ->and($totals->every(fn ($t) => $t['is_failed'] === false))->toBeTrue();
});

it('switches totals to failed when filtering by failed status', function () {
    $company = consoleCompany('failed');
    consolePayment($company, ['amount' => 1000]);
    consolePayment($company, ['amount' => 5000, 'payment_status' => CompanyPayment::STATUS_FAILED, 'paid_at' => null]);

    $response = $this->actingAs(consoleAdmin())
        ->get('/admin/payments?payment_status=failed', ['X-Inertia' => 'true'])
        ->assertOk();

    $totals = collect($response->json('props.totals'));

    expect($totals->firstWhere('currency', 'USD')['total'])->toBe(5000)
        ->and($totals->firstWhere('currency', 'USD')['is_failed'])->toBeTrue();
});

it('flags only the most recent successful payment per company', function () {
    $company = consoleCompany('latest');
    $old = consolePayment($company, ['paid_at' => now()->subDays(5)]);
    $new = consolePayment($company, ['paid_at' => now()->subDay()]);

    $rows = collect($this->actingAs(consoleAdmin())
        ->get('/admin/payments?period=all', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.payments.data'));

    expect($rows->firstWhere('id', $new->id)['is_latest_for_company'])->toBeTrue()
        ->and($rows->firstWhere('id', $old->id)['is_latest_for_company'])->toBeFalse();
});

it('exposes only the allowlisted gateway response to the admin', function () {
    $company = consoleCompany('resp');
    consolePayment($company, [
        'payment_response' => ['last4' => '4242', 'cvc' => '123', 'cardholder_name' => 'John Doe'],
    ]);

    $row = $this->actingAs(consoleAdmin())
        ->get('/admin/payments', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.payments.data.0');

    expect($row['payment_response'])->toHaveKey('last4')
        ->and($row['payment_response'])->not->toHaveKey('cvc')
        ->and($row['payment_response'])->not->toHaveKey('cardholder_name');
});

it('filters by company name', function () {
    consolePayment(consoleCompany('alpha', ['name' => 'Alpha Corp']));
    consolePayment(consoleCompany('beta', ['name' => 'Beta LLC']));

    $rows = collect($this->actingAs(consoleAdmin())
        ->get('/admin/payments?company_name=Alpha', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.payments.data'));

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['company_name'])->toBe('Alpha Corp');
});

it('does not 500 on a crafted array filter param', function () {
    consolePayment(consoleCompany('arr'));

    $status = $this->actingAs(consoleAdmin())
        ->get('/admin/payments?country[]=x', ['X-Inertia' => 'true'])
        ->status();

    expect($status)->not->toBe(500);
});
