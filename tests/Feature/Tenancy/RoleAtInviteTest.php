<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\CreateCompanyAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\InviteEmployeesAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\CompanyInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/*
 * Role-at-invite: an invite may carry an optional company-scoped role that is
 * granted to the invitee on acceptance. The SECURITY spine is that the role MUST
 * belong to the inviter's active company — a foreign/global/template role is
 * rejected (validation) and dropped (action defence in depth). Default is no role,
 * preserving the original email-only behaviour. Uses the global rbac* helpers.
 */

beforeEach(function () {
    // Invites notify the raw email on demand (SendCompanyInvitationJob runs inline
    // on the sync queue). Fake notifications so sends are harmless while the clone
    // job still runs inline.
    Notification::fake();
});

it('stores the chosen company role on the invitation', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');

    // Create + activate the company over HTTP; the sync queue clones the templates
    // inline, so the company has its `member` role for the dropdown.
    $this->actingAs($owner)->post('/companies/create/step1', ['name' => 'Acme'])->assertRedirect();
    $company = Company::first();
    $member = Role::where('company_id', $company->id)->where('slug', 'member')->firstOrFail();

    $this->actingAs($owner)->post('/companies/create/step3', [
        'invitations' => [['email' => 'a@x.test', 'role_id' => $member->id]],
    ])->assertRedirect();

    expect(CompanyInvitation::where('email', 'a@x.test')->value('role_id'))->toBe($member->id);
});

it('defaults to no role when none is chosen (backward compatible)', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $this->actingAs($owner)->post('/companies/create/step1', ['name' => 'Acme'])->assertRedirect();

    $this->actingAs($owner)->post('/companies/create/step3', [
        'invitations' => [['email' => 'b@x.test']],
    ])->assertRedirect();

    expect(CompanyInvitation::where('email', 'b@x.test')->value('role_id'))->toBeNull();
});

it('SECURITY: validation rejects a role_id that is not a company role', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $this->actingAs($owner)->post('/companies/create/step1', ['name' => 'Acme'])->assertRedirect();

    // A role in ANOTHER company, plus the global and template roles — none of these
    // is a role of company A, so each must be refused.
    $companyB = rbacCompany(rbacUser('ownerb@x.test'), 'Beta');
    $foreignRole = Role::create(['name' => 'Foreign', 'slug' => 'foreign', 'company_id' => $companyB->id]);
    $globalRole = Role::where('slug', 'authenticated')->where('is_global', true)->firstOrFail();
    $templateRole = Role::where('slug', 'member')->where('is_template', true)->firstOrFail();

    foreach ([$foreignRole->id, $globalRole->id, $templateRole->id] as $badId) {
        $this->actingAs($owner)->post('/companies/create/step3', [
            'invitations' => [['email' => 'x@x.test', 'role_id' => $badId]],
        ])->assertSessionHasErrors('invitations.0.role_id');
    }

    expect(CompanyInvitation::count())->toBe(0);
});

it('SECURITY: the action drops a role_id that is not a company role', function () {
    // Defence in depth: even called directly (no FormRequest), the action only
    // honours a role that belongs to the company; anything else degrades to null
    // rather than failing the whole invite.
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $companyA = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);
    $memberA = Role::where('company_id', $companyA->id)->where('slug', 'member')->firstOrFail();

    $companyB = rbacCompany(rbacUser('ownerb@x.test'), 'Beta');
    $foreignRole = Role::create(['name' => 'Foreign', 'slug' => 'foreign', 'company_id' => $companyB->id]);
    $globalRole = Role::where('slug', 'authenticated')->where('is_global', true)->firstOrFail();

    app(InviteEmployeesAction::class)->execute($companyA, [
        ['email' => 'foreign@x.test', 'role_id' => $foreignRole->id],
        ['email' => 'global@x.test', 'role_id' => $globalRole->id],
        ['email' => 'valid@x.test', 'role_id' => $memberA->id],
    ], $owner->id);

    expect(CompanyInvitation::where('email', 'foreign@x.test')->value('role_id'))->toBeNull()
        ->and(CompanyInvitation::where('email', 'global@x.test')->value('role_id'))->toBeNull()
        ->and(CompanyInvitation::where('email', 'valid@x.test')->value('role_id'))->toBe($memberA->id);
});

it('re-inviting the same email replaces the role (last write wins)', function () {
    // Re-inviting refreshes the whole invitation (token, expiry AND role). Choosing
    // "Specify later" the second time clears a previously-set role — intended,
    // consistent last-write-wins semantics, pinned here so it can't drift silently.
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);
    $member = Role::where('company_id', $company->id)->where('slug', 'member')->firstOrFail();
    $action = app(InviteEmployeesAction::class);

    $action->execute($company, [['email' => 'a@x.test', 'role_id' => $member->id]], $owner->id);
    expect(CompanyInvitation::where('email', 'a@x.test')->value('role_id'))->toBe($member->id);

    $action->execute($company, [['email' => 'a@x.test', 'role_id' => null]], $owner->id);

    // One row (updateOrCreate, not a duplicate), role cleared.
    expect(CompanyInvitation::where('company_id', $company->id)->where('email', 'a@x.test')->count())->toBe(1)
        ->and(CompanyInvitation::where('email', 'a@x.test')->value('role_id'))->toBeNull();
});

it('SECURITY: drops the grant when the invited role moved to another company before accept', function () {
    // Defence in depth at accept time: even a role that passed invite-time scoping
    // must be re-checked against the invitation's company, in case it was moved.
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);
    $member = Role::where('company_id', $company->id)->where('slug', 'member')->firstOrFail();

    $invitee = rbacUser('invitee@x.test');
    $invitation = $company->invitations()->create([
        'email' => 'invitee@x.test',
        'role_id' => $member->id,
        'token' => CompanyInvitation::generateToken(),
        'status' => CompanyInvitation::STATUS_PENDING,
        'expires_at' => now()->addDays(7),
        'invited_by' => $owner->id,
    ]);

    // The role is reassigned to a different company between invite and accept.
    $companyB = rbacCompany(rbacUser('ownerb@x.test'), 'Beta');
    $member->update(['company_id' => $companyB->id]);

    $this->actingAs($invitee)->post("/invitations/{$invitation->token}/accept")->assertRedirect();

    $invitee = $invitee->fresh();

    expect($invitee->companies()->whereKey($company->id)->exists())->toBeTrue()
        ->and($invitee->getRolesInCompany($company))->toHaveCount(0)
        ->and($invitee->getRolesInCompany($companyB))->toHaveCount(0);
});

it('assigns the invited role to the user on acceptance', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);
    $member = Role::where('company_id', $company->id)->where('slug', 'member')->firstOrFail();

    $invitee = rbacUser('invitee@x.test');
    $invitation = $company->invitations()->create([
        'email' => 'invitee@x.test',
        'role_id' => $member->id,
        'token' => CompanyInvitation::generateToken(),
        'status' => CompanyInvitation::STATUS_PENDING,
        'expires_at' => now()->addDays(7),
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitee)
        ->post("/invitations/{$invitation->token}/accept")
        ->assertRedirect();

    $invitee = $invitee->fresh();

    expect($invitee->companies()->whereKey($company->id)->exists())->toBeTrue()
        ->and($invitee->hasRole('member', $company))->toBeTrue()
        // The grant is scoped to the right company (user_roles.company_id correct).
        ->and($invitee->getRolesInCompany($company)->pluck('slug')->all())->toContain('member');
});

it('accepts without a grant when the invite carried no role', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);

    $invitee = rbacUser('invitee@x.test');
    $invitation = $company->invitations()->create([
        'email' => 'invitee@x.test',
        'token' => CompanyInvitation::generateToken(),
        'status' => CompanyInvitation::STATUS_PENDING,
        'expires_at' => now()->addDays(7),
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitee)->post("/invitations/{$invitation->token}/accept")->assertRedirect();

    $invitee = $invitee->fresh();

    expect($invitee->companies()->whereKey($company->id)->exists())->toBeTrue()
        ->and($invitee->getRolesInCompany($company))->toHaveCount(0);
});

it('accepts gracefully when the invited role was deleted before acceptance', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);
    $member = Role::where('company_id', $company->id)->where('slug', 'member')->firstOrFail();

    $invitee = rbacUser('invitee@x.test');
    $invitation = $company->invitations()->create([
        'email' => 'invitee@x.test',
        'role_id' => $member->id,
        'token' => CompanyInvitation::generateToken(),
        'status' => CompanyInvitation::STATUS_PENDING,
        'expires_at' => now()->addDays(7),
        'invited_by' => $owner->id,
    ]);

    // The role disappears between invite and accept.
    $member->delete();

    $this->actingAs($invitee)->post("/invitations/{$invitation->token}/accept")->assertRedirect();

    $invitee = $invitee->fresh();

    expect($invitee->companies()->whereKey($company->id)->exists())->toBeTrue()
        ->and($invitee->getRolesInCompany($company))->toHaveCount(0);
});
