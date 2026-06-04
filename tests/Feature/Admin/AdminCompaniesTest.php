<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\ActivityLog\Models\Activity as ActivityModel;
use Dmitryisaenko\LaraFoundry\Auth\Models\UserSession;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['larafoundry-activitylog.geo.enabled' => false]);
    config(['inertia.testing.ensure_pages_exist' => false]);
});

function acAdmin(string $email = 'boss@x.test'): User
{
    return User::create([
        'name' => 'Boss',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
}

function acUser(string $email = 'joe@x.test'): User
{
    return User::create([
        'name' => 'Joe',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

function acCompany(User $owner, string $name = 'Acme'): Company
{
    $company = Company::create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.uniqid(),
        'created_by_id' => $owner->id,
    ]);

    $company->addEmployee($owner, addedById: $owner->id, isOwner: true);

    return $company;
}

it('forbids a non-admin from the company list', function () {
    $this->actingAs(acUser())->get('/admin/companies')->assertForbidden();
});

it('redirects a guest from the company list', function () {
    $this->get('/admin/companies')->assertRedirect();
});

it('lets a super-admin list companies', function () {
    $admin = acAdmin();
    acCompany(acUser('a@x.test'), 'One');
    acCompany(acUser('b@x.test'), 'Two');

    $this->actingAs($admin)
        ->get('/admin/companies', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Admin/Companies/Index')
        ->assertJsonCount(2, 'props.companies.data');
});

it('counts only non-removed employees', function () {
    $admin = acAdmin();
    $owner = acUser('owner@x.test');
    $company = acCompany($owner, 'Acme');
    $member = acUser('member@x.test');
    $company->addEmployee($member, addedById: $owner->id);

    // soft-remove the member — the count should drop back to the owner only.
    $company->removeEmployee($member);

    $this->actingAs($admin)
        ->get('/admin/companies', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.companies.data.0.employees_count', 1);
});

it('searches companies by name and owner email', function () {
    $admin = acAdmin();
    acCompany(acUser('founder@needle.test'), 'Alpha');
    acCompany(acUser('other@x.test'), 'Beta');

    $this->actingAs($admin)
        ->getJson('/admin/companies/search?search=needle')
        ->assertOk()
        ->assertJsonCount(1, 'companies')
        ->assertJsonPath('companies.0.name', 'Alpha');
});

it('shows the read-only company detail', function () {
    $admin = acAdmin();
    $company = acCompany(acUser('det@x.test'), 'Detail Co');

    $this->actingAs($admin)
        ->get("/admin/companies/{$company->uuid}", ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Admin/Companies/Show')
        ->assertJsonPath('props.company.data.name', 'Detail Co');
});

it('reports subscription status from the billing columns, read-only', function () {
    config(['larafoundry.billing.enabled' => true]);
    $admin = acAdmin();
    // Billing columns are not $fillable (mass-assign guard); a trial is set
    // server-side via forceFill, exactly as the billing add-on would.
    acCompany(acUser('trial@x.test'), 'Trialled')
        ->forceFill(['trial_ends_at' => now()->addDays(5)])->save();

    $response = $this->actingAs($admin)
        ->get('/admin/companies', ['X-Inertia' => 'true'])
        ->assertOk();

    $sub = $response->json('props.companies.data.0.subscription');

    expect($sub['status'])->toBe('on_trial')
        ->and($sub['has_access'])->toBeTrue();
});

it('shows never_activated with access when billing is disabled', function () {
    config(['larafoundry.billing.enabled' => false]);
    $admin = acAdmin();
    acCompany(acUser('free@x.test'), 'Free Co');

    $response = $this->actingAs($admin)
        ->get('/admin/companies', ['X-Inertia' => 'true'])
        ->assertOk();

    $sub = $response->json('props.companies.data.0.subscription');

    // Free core: no billing wired, so no subscription to report, but access is
    // open — honest, not a bug.
    expect($sub['status'])->toBe('never_activated')
        ->and($sub['has_access'])->toBeTrue();
});

it('never exposes payment sums on the company resource', function () {
    $admin = acAdmin();
    acCompany(acUser('np@x.test'), 'No Payments Co');

    $first = $this->actingAs($admin)
        ->get('/admin/companies', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.companies.data.0');

    expect($first)->not->toHaveKey('payments_sum')
        ->and($first)->not->toHaveKey('last_payment')
        ->and($first['subscription'])->not->toHaveKey('amount');
});

it('blocks a company, purges its sessions and logs it', function () {
    $admin = acAdmin();
    $owner = acUser('blkown@x.test');
    $company = acCompany($owner, 'Blockable');

    UserSession::create([
        'user_id' => $owner->id,
        'session_id' => 'sess-1',
        'active_company_id' => $company->id,
        'last_activity' => now(),
    ]);

    $this->actingAs($admin)
        ->post("/admin/companies/{$company->uuid}/block", ['reason' => 'abuse'])
        ->assertRedirect();

    $fresh = $company->fresh();

    expect($fresh->company_blocked_at)->not->toBeNull()
        ->and($fresh->company_blocked_reason)->toBe('abuse')
        ->and($fresh->isBlocked())->toBeTrue()
        ->and(UserSession::where('active_company_id', $company->id)->count())->toBe(0);

    expect(ActivityModel::where('description', 'admin.company.blocked')->exists())->toBeTrue();
});

it('does not purge sessions scoped to other companies', function () {
    $admin = acAdmin();
    $owner = acUser('multi@x.test');
    $blocked = acCompany($owner, 'Blocked Co');
    $other = acCompany($owner, 'Other Co');

    UserSession::create([
        'user_id' => $owner->id, 'session_id' => 's-blocked',
        'active_company_id' => $blocked->id, 'last_activity' => now(),
    ]);
    UserSession::create([
        'user_id' => $owner->id, 'session_id' => 's-other',
        'active_company_id' => $other->id, 'last_activity' => now(),
    ]);

    $this->actingAs($admin)->post("/admin/companies/{$blocked->uuid}/block")->assertRedirect();

    expect(UserSession::where('active_company_id', $blocked->id)->count())->toBe(0)
        ->and(UserSession::where('active_company_id', $other->id)->count())->toBe(1);
});

it('unblocks a company and logs it', function () {
    $admin = acAdmin();
    $company = acCompany(acUser('unblk@x.test'), 'Unblockable');
    $company->forceFill(['company_blocked_at' => now(), 'company_blocked_reason' => 'x'])->save();

    $this->actingAs($admin)
        ->post("/admin/companies/{$company->uuid}/unblock")
        ->assertRedirect();

    $fresh = $company->fresh();

    expect($fresh->company_blocked_at)->toBeNull()
        ->and($fresh->company_blocked_reason)->toBeNull();

    expect(ActivityModel::where('description', 'admin.company.unblocked')->exists())->toBeTrue();
});

it('forbids a non-admin from blocking a company', function () {
    $company = acCompany(acUser('victim@x.test'), 'Target');

    $this->actingAs(acUser('attacker@x.test'))
        ->post("/admin/companies/{$company->uuid}/block")
        ->assertForbidden();

    expect($company->fresh()->company_blocked_at)->toBeNull();
});

it('filters companies by block state', function () {
    $admin = acAdmin();
    $blocked = acCompany(acUser('b1@x.test'), 'Blocked');
    $blocked->forceFill(['company_blocked_at' => now()])->save();
    acCompany(acUser('a1@x.test'), 'Active');

    $this->actingAs($admin)
        ->get('/admin/companies?blockState=blocked', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonCount(1, 'props.companies.data')
        ->assertJsonPath('props.companies.data.0.name', 'Blocked');
});

it('paginates the company list at the configured page size', function () {
    config(['larafoundry.admin.companies_per_page' => 2]);
    $admin = acAdmin();
    acCompany(acUser('p1@x.test'), 'C1');
    acCompany(acUser('p2@x.test'), 'C2');
    acCompany(acUser('p3@x.test'), 'C3');

    $this->actingAs($admin)
        ->get('/admin/companies', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.pagination.per_page', 2)
        ->assertJsonCount(2, 'props.companies.data');
});

it('ignores an array-shaped filter param instead of crashing', function () {
    $admin = acAdmin();
    acCompany(acUser('arr@x.test'), 'Arr Co');

    // A crafted ?search[]=x makes the value an array; the base Filter must skip
    // it (non-scalar) rather than passing it to the string-typed method (500).
    $this->actingAs($admin)
        ->get('/admin/companies?search[]=x', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonCount(1, 'props.companies.data');
});

it('does not let company_blocked_at be mass-assigned', function () {
    $owner = acUser('ma@x.test');

    $company = Company::create([
        'name' => 'Sneaky',
        'slug' => 'sneaky-'.uniqid(),
        'created_by_id' => $owner->id,
        'company_blocked_at' => null,
        // a malicious caller trying to pre-unblock — must be ignored by guard.
        'company_blocked_reason' => 'should-not-stick',
    ]);

    expect($company->fresh()->company_blocked_reason)->toBeNull();
});
