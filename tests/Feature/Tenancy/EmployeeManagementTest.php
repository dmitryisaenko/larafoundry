<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Models\UserSession;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\RemoveEmployeeAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\CompanyInvitation;
use Dmitryisaenko\LaraFoundry\Tenancy\Resolvers\SessionTenantResolver;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Employee management on the active company, with the cross-company IDOR guard
 * as the headline: an owner of company A must not be able to resend or delete an
 * invitation that belongs to company B, even with its id. Removal is soft and
 * owners can't be removed via this path.
 */

function member(string $email): User
{
    return User::create([
        'name' => 'M',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

function companyOwnedBy(User $owner, string $name = 'Acme'): Company
{
    $company = Company::create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.uniqid(),
        'created_by_id' => $owner->id,
    ]);
    $company->addEmployee($owner, addedById: $owner->id, isOwner: true);

    return $company;
}

/**
 * Start a real session and a tracked user_sessions row, then mark $company the
 * active tenant via the resolver — the genuine round-trip the HTTP layer relies
 * on, without depending on a session cookie surviving between requests.
 */
function activateCompanyFor(User $user, Company $company): void
{
    $store = new Store('larafoundry_session', new ArraySessionHandler(120), str_pad('emp'.$user->id, 40, 'a'));
    $store->start();
    app()->instance('session.store', $store);
    request()->setLaravelSession($store);

    UserSession::create([
        'user_id' => $user->id,
        'session_id' => $store->getId(),
        'login_method' => 'native',
    ]);

    app(SessionTenantResolver::class)->setCurrent($user, $company);
}

it('lists employees of the active company', function () {
    $owner = member('owner@x.test');
    $company = companyOwnedBy($owner);
    $employee = member('emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);

    // SetActiveTenant (on the route group) auto-selects the owned company.
    // X-Inertia makes Inertia return a JSON page object (no root Blade view
    // needed in the package test harness).
    $this->actingAs($owner)
        ->withHeader('X-Inertia', 'true')
        ->get('/employees')
        ->assertOk();
});

it('soft-removes an employee', function () {
    $owner = member('owner@x.test');
    $company = companyOwnedBy($owner);
    $employee = member('emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);

    $this->actingAs($owner)->delete('/employees', ['user_id' => $employee->id])->assertRedirect();

    expect($employee->fresh()->companies()->count())->toBe(0)
        // Row is kept (soft), not hard-deleted.
        ->and(DB::table('company_user')->where('user_id', $employee->id)->where('is_deleted', true)->count())->toBe(1);
});

it('refuses to remove an owner', function () {
    $owner = member('owner@x.test');
    companyOwnedBy($owner);

    $this->actingAs($owner)->delete('/employees', ['user_id' => $owner->id])->assertForbidden();
});

it('re-points a removed employee to their next company via the action', function () {
    // Regression: the re-point check must run BEFORE the membership is removed,
    // else getCurrentCompanyId() already resolves null and the move is skipped.
    $owner = member('owner@x.test');
    $companyA = companyOwnedBy($owner, 'A');
    $companyB = companyOwnedBy($owner, 'B');

    $employee = member('emp@x.test');
    $companyA->addEmployee($employee, addedById: $owner->id);
    $companyB->addEmployee($employee, addedById: $owner->id);

    // Make A the employee's active company over a genuine session round-trip,
    // then remove them from A.
    activateCompanyFor($employee, $companyA);
    expect($employee->getActiveCompany()?->getKey())->toBe($companyA->id);

    app(RemoveEmployeeAction::class)
        ->execute($companyA, $employee);

    // They should now be acting as B (their remaining company), not stranded on A.
    expect($employee->getActiveCompany()?->getKey())->toBe($companyB->id);
});

it('SECURITY: cannot delete an invitation belonging to another company (IDOR)', function () {
    // Company A, owned by us (auto-selected active).
    $owner = member('owner@x.test');
    companyOwnedBy($owner, 'A');

    // Company B (someone else's) with its own pending invitation.
    $otherOwner = member('other@x.test');
    $companyB = companyOwnedBy($otherOwner, 'B');
    $foreignInvite = $companyB->invitations()->create([
        'email' => 'target@x.test',
        'token' => CompanyInvitation::generateToken(),
        'status' => CompanyInvitation::STATUS_PENDING,
        'expires_at' => now()->addDays(7),
    ]);

    // Resolved through the active company's relation, so a foreign invite simply
    // isn't found (404) — isolation is structural, not a manual guard.
    $this->actingAs($owner)->delete("/employees/invitations/{$foreignInvite->id}")->assertNotFound();

    expect($foreignInvite->fresh())->not->toBeNull();
});

it('SECURITY: cannot resend an invitation belonging to another company (IDOR)', function () {
    Queue::fake();
    $owner = member('owner@x.test');
    companyOwnedBy($owner, 'A');

    $otherOwner = member('other@x.test');
    $companyB = companyOwnedBy($otherOwner, 'B');
    $foreignInvite = $companyB->invitations()->create([
        'email' => 'target@x.test',
        'token' => CompanyInvitation::generateToken(),
        'status' => CompanyInvitation::STATUS_PENDING,
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($owner)->post("/employees/invitations/{$foreignInvite->id}/resend")->assertNotFound();
});

it('a non-owner member cannot invite', function () {
    $owner = member('owner@x.test');
    $company = companyOwnedBy($owner);
    $employee = member('emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);

    // The member's only company is auto-selected active; they still can't invite.
    $this->actingAs($employee)->post('/employees/invite', ['email' => 'new@x.test'])->assertForbidden();
});

it('an owner cannot request to leave their own company (would orphan it)', function () {
    $owner = member('owner@x.test');
    companyOwnedBy($owner);

    $this->actingAs($owner)->post('/employees/request-removal')->assertForbidden();
});

it('a tenant-less member reaching request-removal fails gracefully, not 403', function () {
    // The route is exempt from EnsureActiveTenant so a member whose only company
    // was removed can still reach it. With no active company there is nothing to
    // leave, so it must redirect back with an error — NOT abort 403.
    // Pin the exemption explicitly so the test is independent of config merge.
    config()->set('larafoundry.tenancy.routes_without_active_tenant', [
        'tenancy.employees.request-removal',
        'tenancy.employees.cancel-removal',
    ]);

    $user = member('lonely@x.test');

    $this->actingAs($user)
        ->from('/somewhere')
        ->post('/employees/request-removal')
        ->assertRedirect('/somewhere')
        ->assertSessionHas('error');
});

it('a member can request to leave', function () {
    $owner = member('owner@x.test');
    $company = companyOwnedBy($owner);
    $employee = member('emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);

    $this->actingAs($employee)->post('/employees/request-removal')->assertRedirect();

    expect(DB::table('company_user')
        ->where('user_id', $employee->id)
        ->whereNotNull('removal_requested_at')
        ->count())->toBe(1);
});
