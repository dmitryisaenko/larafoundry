<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\CreateCompanyAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\CreateEmployeeAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/*
 * Create-employee-directly: an OWNER provisions a member account (name, email,
 * password, roles) without the email invite. The account is created verified (the
 * owner vouches). SECURITY spine: owner-only, email unique + super-admin-reserved,
 * and every role must belong to the owner's active company (rejected by validation
 * AND dropped by the action). Uses the global rbac* helpers.
 */

beforeEach(function () {
    Notification::fake();
});

it('lets an owner create a verified member with roles', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);
    $member = Role::where('company_id', $company->id)->where('slug', 'member')->firstOrFail();

    $this->actingAs($owner)->post('/employees', [
        'name' => 'Grace',
        'lastname' => 'Hopper',
        'email' => 'grace@x.test',
        'password' => 'Secret-Password-123',
        'password_confirmation' => 'Secret-Password-123',
        'role_ids' => [$member->id],
    ])->assertRedirect();

    $created = ($owner::query())->where('email', 'grace@x.test')->firstOrFail();

    expect($created->name)->toBe('Grace')
        ->and($created->lastname)->toBe('Hopper')
        ->and($created->email_verified_at)->not->toBeNull()
        ->and($created->companies()->whereKey($company->id)->exists())->toBeTrue()
        ->and($created->hasRole('member', $company))->toBeTrue();
});

it('lowercases the email and can create a member with no roles', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);

    $this->actingAs($owner)->post('/employees', [
        'name' => 'Ada',
        'email' => 'ADA@X.TEST',
        'password' => 'Secret-Password-123',
        'password_confirmation' => 'Secret-Password-123',
    ])->assertRedirect();

    $created = ($owner::query())->where('email', 'ada@x.test')->firstOrFail();

    expect($created->email)->toBe('ada@x.test')
        ->and($created->companies()->whereKey($company->id)->exists())->toBeTrue()
        ->and($created->getRolesInCompany($company))->toHaveCount(0);
});

it('SECURITY: a non-owner member cannot create employees', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);

    // A plain member of the same company (their only membership → auto-selected as
    // the active tenant), but NOT the owner.
    $worker = rbacUser('worker@x.test');
    $company->addEmployee($worker, addedById: $owner->id, isOwner: false);

    $this->actingAs($worker)->post('/employees', [
        'name' => 'Mallory',
        'email' => 'mallory@x.test',
        'password' => 'Secret-Password-123',
        'password_confirmation' => 'Secret-Password-123',
    ])->assertForbidden();

    expect(($owner::query())->where('email', 'mallory@x.test')->exists())->toBeFalse();
});

it('SECURITY: validation rejects a role_id that is not a company role', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);

    $companyB = rbacCompany(rbacUser('ownerb@x.test'), 'Beta');
    $foreignRole = Role::create(['name' => 'Foreign', 'slug' => 'foreign', 'company_id' => $companyB->id]);
    $globalRole = Role::where('slug', 'authenticated')->where('is_global', true)->firstOrFail();
    $templateRole = Role::where('slug', 'member')->where('is_template', true)->firstOrFail();

    foreach ([$foreignRole->id, $globalRole->id, $templateRole->id] as $badId) {
        $this->actingAs($owner)->post('/employees', [
            'name' => 'X',
            'email' => 'x@x.test',
            'password' => 'Secret-Password-123',
            'password_confirmation' => 'Secret-Password-123',
            'role_ids' => [$badId],
        ])->assertSessionHasErrors('role_ids.0');
    }

    expect(($owner::query())->where('email', 'x@x.test')->exists())->toBeFalse();
});

it('SECURITY: the action drops a role that is not a company role (defence in depth)', function () {
    // Called directly (no FormRequest): the action only grants roles that belong to
    // the company; a foreign/global role is silently dropped, not an error.
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $companyA = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);
    $memberA = Role::where('company_id', $companyA->id)->where('slug', 'member')->firstOrFail();

    $companyB = rbacCompany(rbacUser('ownerb@x.test'), 'Beta');
    $foreignRole = Role::create(['name' => 'Foreign', 'slug' => 'foreign', 'company_id' => $companyB->id]);
    $globalRole = Role::where('slug', 'authenticated')->where('is_global', true)->firstOrFail();

    $created = app(CreateEmployeeAction::class)->execute($companyA, [
        'name' => 'Grace',
        'email' => 'grace@x.test',
        'password' => 'Secret-Password-123',
        'role_ids' => [$foreignRole->id, $globalRole->id, $memberA->id],
    ], $owner->id);

    expect($created->getRolesInCompany($companyA)->pluck('slug')->all())->toBe(['member']);
});

it('SECURITY: rejects a duplicate email', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);

    $this->actingAs($owner)->post('/employees', [
        'name' => 'Dup',
        // The owner's own address already exists.
        'email' => 'owner@x.test',
        'password' => 'Secret-Password-123',
        'password_confirmation' => 'Secret-Password-123',
    ])->assertSessionHasErrors('email');
});

it('rejects a mismatched password confirmation', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);

    $this->actingAs($owner)->post('/employees', [
        'name' => 'Mismatch',
        'email' => 'mismatch@x.test',
        'password' => 'Secret-Password-123',
        'password_confirmation' => 'DIFFERENT-Password-999',
    ])->assertSessionHasErrors('password');

    expect(($owner::query())->where('email', 'mismatch@x.test')->exists())->toBeFalse();
});

it('rolls back the new user when membership provisioning fails (atomicity)', function () {
    // The account, its membership and its roles are one unit of work: if a later
    // write throws, the user INSERT must not survive to squat on the unique email.
    rbacSeed();
    $owner = rbacUser('owner@x.test');

    // A company whose addEmployee blows up mid-provisioning (a DB error stand-in).
    $company = Mockery::mock(Company::class)->makePartial();
    $company->shouldReceive('addEmployee')->once()->andThrow(new RuntimeException('boom'));

    expect(fn () => app(CreateEmployeeAction::class)->execute($company, [
        'name' => 'Ghost',
        'email' => 'ghost@x.test',
        'password' => 'Secret-Password-123',
    ], $owner->id))->toThrow(RuntimeException::class);

    expect(($owner::query())->where('email', 'ghost@x.test')->exists())->toBeFalse();
});
