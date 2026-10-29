<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Models\CompanyPayment;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tests\Billing\Fixtures\BillableCompany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The `payments` relation the add-on registers on the core Company via
 * resolveRelationUsing (sub-phase B7a): it exists without the core model carrying a
 * `payments()` method, it resolves on both the core class and a host Billable subclass,
 * and it scopes strictly to the owning company.
 */
function relationCompany(string $slug): BillableCompany
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

function seedPayment(BillableCompany $company, string $status): CompanyPayment
{
    return CompanyPayment::query()->create([
        'company_id' => $company->getTenantKey(),
        'user_id' => $company->created_by_id,
        'plan_id' => 'pro',
        'billing_period' => CompanyPayment::PERIOD_MONTHLY,
        'amount' => 1000,
        'currency' => 'USD',
        'payment_status' => $status,
    ]);
}

it('exposes a payments HasMany on the Billable Company without a hardcoded method', function () {
    // The core Company carries no `payments` method — the relation is dynamic.
    expect(method_exists(Company::class, 'payments'))->toBeFalse();

    $company = relationCompany('rel');
    seedPayment($company, CompanyPayment::STATUS_SUCCESS);
    seedPayment($company, CompanyPayment::STATUS_FAILED);

    expect($company->payments())->toBeInstanceOf(HasMany::class)
        ->and($company->payments()->count())->toBe(2)
        ->and($company->payments()->exists())->toBeTrue()
        ->and($company->payments)->toHaveCount(2);
});

it('resolves the relation when fetched through the core Company class (subclass inheritance)', function () {
    $company = relationCompany('rel-core');
    seedPayment($company, CompanyPayment::STATUS_SUCCESS);

    $coreInstance = Company::query()->whereKey($company->getKey())->first();

    expect($coreInstance->payments()->count())->toBe(1);
});

it('scopes payments to the owning company', function () {
    $a = relationCompany('rel-a');
    $b = relationCompany('rel-b');
    seedPayment($a, CompanyPayment::STATUS_SUCCESS);
    seedPayment($a, CompanyPayment::STATUS_SUCCESS);
    seedPayment($b, CompanyPayment::STATUS_SUCCESS);

    expect($a->payments()->count())->toBe(2)
        ->and($b->payments()->count())->toBe(1);
});
