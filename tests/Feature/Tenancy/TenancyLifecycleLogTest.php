<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\ActivityLog\Models\Activity as ActivityModel;
use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\CreateCompanyAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\CompanyInvitation;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Phase 1 (activity completeness): the owner-employee lifecycle events must reach
 * the activity log under the Tenancy group. These drive the real routes/actions
 * and assert an Activity row lands. Local fixtures (tll*) keep the file
 * self-contained; the global rbac* helpers are used for the role-change tests.
 */

beforeEach(function () {
    config(['larafoundry-activitylog.geo.enabled' => false]);
});

function tllUser(string $email): User
{
    return User::create([
        'name' => 'U',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

function tllCompany(User $owner, string $name = 'Acme'): Company
{
    $company = Company::create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.uniqid(),
        'created_by_id' => $owner->id,
    ]);
    $company->addEmployee($owner, addedById: $owner->id, isOwner: true);

    return $company;
}

function tllInvite(Company $company, string $email): CompanyInvitation
{
    return $company->invitations()->create([
        'email' => $email,
        'token' => CompanyInvitation::generateToken(),
        'status' => CompanyInvitation::STATUS_PENDING,
        'expires_at' => now()->addDays(7),
    ]);
}

it('logs InvitationAccepted when an invitee joins', function () {
    $owner = tllUser('acc-owner@x.test');
    $company = tllCompany($owner);
    $invitation = tllInvite($company, 'join@x.test');
    $invitee = tllUser('join@x.test');

    $this->actingAs($invitee)->post("/invitations/{$invitation->token}/accept")->assertRedirect();

    $entry = ActivityModel::query()->where('event', 'InvitationAccepted')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->log_name)->toBe('Tenancy')
        ->and($entry->causer_id)->toBe($invitee->id)
        ->and($entry->properties['event_properties']['invited_email'] ?? null)->toBe('join@x.test');
});

it('logs InvitationRejected when an invitee declines', function () {
    $owner = tllUser('rej-owner@x.test');
    $invitation = tllInvite(tllCompany($owner), 'decline@x.test');
    $invitee = tllUser('decline@x.test');

    $this->actingAs($invitee)->post("/invitations/{$invitation->token}/reject")->assertRedirect();

    expect(ActivityModel::query()->where('event', 'InvitationRejected')->exists())->toBeTrue();
});

it('logs InvitationResent when an owner re-sends', function () {
    Queue::fake();
    $owner = tllUser('resend-owner@x.test');
    $company = tllCompany($owner);
    $invitation = tllInvite($company, 'resend@x.test');

    $this->actingAs($owner)->post("/employees/invitations/{$invitation->id}/resend")->assertRedirect();

    expect(ActivityModel::query()->where('event', 'InvitationResent')->exists())->toBeTrue();
});

it('logs InvitationWithdrawn when an owner deletes a pending invite', function () {
    $owner = tllUser('withdraw-owner@x.test');
    $company = tllCompany($owner);
    $invitation = tllInvite($company, 'withdraw@x.test');

    $this->actingAs($owner)->delete("/employees/invitations/{$invitation->id}")->assertRedirect();

    $entry = ActivityModel::query()->where('event', 'InvitationWithdrawn')->latest('id')->first();
    expect($entry)->not->toBeNull()
        // Dispatched before delete, so the invited address is still recorded.
        ->and($entry->properties['event_properties']['invited_email'] ?? null)->toBe('withdraw@x.test');
});

it('logs EmployeeRemovalRequested when a member asks to leave', function () {
    $owner = tllUser('rr-owner@x.test');
    $company = tllCompany($owner);
    $employee = tllUser('rr-emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);

    $this->actingAs($employee)->post('/employees/request-removal')->assertRedirect();

    $entry = ActivityModel::query()->where('event', 'EmployeeRemovalRequested')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->log_name)->toBe('Tenancy')
        ->and($entry->causer_id)->toBe($employee->id);
});

it('logs EmployeeRemovalCancelled when a member withdraws their request', function () {
    $owner = tllUser('rc-owner@x.test');
    $company = tllCompany($owner);
    $employee = tllUser('rc-emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);

    $this->actingAs($employee)->post('/employees/request-removal')->assertRedirect();
    $this->actingAs($employee)->post('/employees/cancel-removal')->assertRedirect();

    expect(ActivityModel::query()->where('event', 'EmployeeRemovalCancelled')->exists())->toBeTrue();
});

it('does NOT log a phantom EmployeeRemovalCancelled when nothing was pending', function () {
    $owner = tllUser('phantom-owner@x.test');
    $company = tllCompany($owner);
    $employee = tllUser('phantom-emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);

    // Cancel with no prior request → idempotent no-op → no audit event.
    $this->actingAs($employee)->post('/employees/cancel-removal')->assertRedirect();

    expect(ActivityModel::query()->where('event', 'EmployeeRemovalCancelled')->count())->toBe(0);
});

it('does NOT log a duplicate EmployeeRemovalRequested on a re-request', function () {
    $owner = tllUser('dup-owner@x.test');
    $company = tllCompany($owner);
    $employee = tllUser('dup-emp@x.test');
    $company->addEmployee($employee, addedById: $owner->id);

    // Two requests in a row → the second is a no-op → exactly one audit event.
    $this->actingAs($employee)->post('/employees/request-removal')->assertRedirect();
    $this->actingAs($employee)->post('/employees/request-removal')->assertRedirect();

    expect(ActivityModel::query()->where('event', 'EmployeeRemovalRequested')->count())->toBe(1);
});

it('logs EmployeeRoleChanged when an owner changes a member role set', function () {
    rbacSeed();
    $owner = rbacUser('erc-owner@x.test');
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);
    $memberRole = Role::where('company_id', $company->id)->where('slug', 'member')->firstOrFail();

    $worker = rbacUser('erc-worker@x.test');
    $company->addEmployee($worker, addedById: $owner->id);

    $this->actingAs($owner)->post("/employees/{$worker->id}", [
        'name' => 'Grace',
        'manage_roles' => true,
        'role_ids' => [$memberRole->id],
    ])->assertRedirect();

    $entry = ActivityModel::query()->where('event', 'EmployeeRoleChanged')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->log_name)->toBe('Tenancy')
        ->and($entry->properties['event_properties']['role_ids'] ?? null)->toBe([$memberRole->id]);
});

it('does NOT log EmployeeRoleChanged on an identity-only edit', function () {
    rbacSeed();
    $owner = rbacUser('noroe-owner@x.test');
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);

    $worker = rbacUser('noroe-worker@x.test');
    $company->addEmployee($worker, addedById: $owner->id);

    // No manage_roles flag → roles untouched → no role-change event.
    $this->actingAs($owner)->post("/employees/{$worker->id}", ['name' => 'Renamed'])->assertRedirect();

    expect(ActivityModel::query()->where('event', 'EmployeeRoleChanged')->count())->toBe(0);
});

it('does NOT log EmployeeRoleChanged when the submitted role set is unchanged', function () {
    rbacSeed();
    $owner = rbacUser('same-owner@x.test');
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);
    $memberRole = Role::where('company_id', $company->id)->where('slug', 'member')->firstOrFail();

    $worker = rbacUser('same-worker@x.test');
    $company->addEmployee($worker, addedById: $owner->id);
    $worker->assignRole($memberRole, $company);

    // Re-submit the SAME role set → sync is a no-op change → no event.
    $this->actingAs($owner)->post("/employees/{$worker->id}", [
        'name' => 'Same',
        'manage_roles' => true,
        'role_ids' => [$memberRole->id],
    ])->assertRedirect();

    expect(ActivityModel::query()->where('event', 'EmployeeRoleChanged')->count())->toBe(0);
});
