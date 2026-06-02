<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Authorization\Models\Permission;
use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The trait is the heart of RBAC: the check priority (super → owner → revoke →
 * grant → company role → global role → deny), tenant isolation by company_id, and
 * the request-memoized resolution. These are unit-level (no HTTP).
 */

beforeEach(function () {
    rbacSeed();
});

function companyRole(string $slug, array $permissions, int $companyId): Role
{
    $role = Role::create([
        'name' => ucfirst($slug),
        'slug' => $slug,
        'is_global' => false,
        'is_template' => false,
        'is_custom' => true,
        'company_id' => $companyId,
    ]);
    $role->syncPermissions($permissions);

    return $role;
}

it('grants a permission through a company role', function () {
    $user = rbacUser();
    $company = rbacCompany(rbacUser('owner@x.test'));
    $company->addEmployee($user, addedById: $company->created_by_id);

    $role = companyRole('viewer', ['company.settings.view'], $company->id);
    $user->assignRole($role, $company);

    expect($user->hasPermissionTo('company.settings.view', $company))->toBeTrue()
        ->and($user->hasPermissionTo('company.settings.update', $company))->toBeFalse();
});

it('lets an individual grant add a permission beyond the user roles', function () {
    $user = rbacUser();
    $company = rbacCompany(rbacUser('owner@x.test'));
    $company->addEmployee($user, addedById: $company->created_by_id);

    $user->givePermissionTo('company.settings.update', $company);

    expect($user->hasPermissionTo('company.settings.update', $company))->toBeTrue();
});

it('lets an individual revocation beat a role grant', function () {
    $user = rbacUser();
    $company = rbacCompany(rbacUser('owner@x.test'));
    $company->addEmployee($user, addedById: $company->created_by_id);

    $role = companyRole('viewer', ['company.settings.view'], $company->id);
    $user->assignRole($role, $company);
    $user->revokePermissionFrom('company.settings.view', $company);

    expect($user->hasPermissionTo('company.settings.view', $company))->toBeFalse();
});

it('gives a company owner every permission via the is_owner bypass', function () {
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner);

    // No roles assigned at all, yet the owner passes any check in their company.
    expect($owner->hasPermissionTo('company.roles.delete', $company))->toBeTrue()
        ->and($owner->getAllPermissions($company))->toContain('company.roles.delete');
});

it('gives a super admin every permission everywhere', function () {
    $admin = rbacUser('admin@x.test', ['is_admin' => true]);
    $company = rbacCompany(rbacUser('owner@x.test'));

    expect($admin->isSuperAdmin())->toBeTrue()
        ->and($admin->hasPermissionTo('company.settings.view', $company))->toBeTrue()
        // even with no company context
        ->and($admin->hasPermissionTo('company.settings.view'))->toBeTrue();
});

it('isolates company-scoped permissions across tenants', function () {
    $user = rbacUser();
    $owner = rbacUser('owner@x.test');
    $companyA = rbacCompany($owner, 'A');
    $companyB = rbacCompany($owner, 'B');
    $companyA->addEmployee($user, addedById: $owner->id);
    $companyB->addEmployee($user, addedById: $owner->id);

    $roleA = companyRole('viewer', ['company.settings.view'], $companyA->id);
    $user->assignRole($roleA, $companyA);

    expect($user->hasPermissionTo('company.settings.view', $companyA))->toBeTrue()
        // same permission, other company → not granted (tenant isolation)
        ->and($user->hasPermissionTo('company.settings.view', $companyB))->toBeFalse();
});

it('applies a global role in every company context', function () {
    $user = rbacUser();
    $company = rbacCompany(rbacUser('owner@x.test'));
    $company->addEmployee($user, addedById: $company->created_by_id);

    // `authenticated` (global, seeded) grants profile.view everywhere.
    $user->assignRole('authenticated');

    expect($user->hasPermissionTo('profile.view', $company))->toBeTrue()
        ->and($user->hasPermissionTo('profile.view'))->toBeTrue();
});

it('matches wildcard permission patterns', function () {
    $user = rbacUser();
    $company = rbacCompany(rbacUser('owner@x.test'));
    $company->addEmployee($user, addedById: $company->created_by_id);
    $user->assignRole(companyRole('viewer', ['company.settings.view'], $company->id), $company);

    expect($user->hasPermissionPattern('company.settings.*', $company))->toBeTrue()
        ->and($user->hasPermissionPattern('orders.*', $company))->toBeFalse();
});

it('detects the authenticated-only state', function () {
    $user = rbacUser();
    $user->assignRole('authenticated');

    expect($user->hasOnlyAuthenticatedRole())->toBeTrue();

    $company = rbacCompany(rbacUser('owner@x.test'));
    $company->addEmployee($user, addedById: $company->created_by_id);
    $user->assignRole(companyRole('viewer', [], $company->id), $company);

    expect($user->fresh()->hasOnlyAuthenticatedRole())->toBeFalse();
});

it('memoizes the resolved permission set within the request', function () {
    $user = rbacUser();
    $company = rbacCompany(rbacUser('owner@x.test'));
    $company->addEmployee($user, addedById: $company->created_by_id);
    $user->assignRole(companyRole('viewer', ['company.settings.view'], $company->id), $company);

    // Warm the memo, then count queries on subsequent checks: they must hit the
    // cached set, not re-query per check.
    $user->getAllPermissions($company);

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $user->hasPermissionTo('company.settings.view', $company);
    $user->hasPermissionTo('company.settings.update', $company);
    $user->hasPermissionTo('company.roles.view', $company);

    expect($queries)->toBe(0);
});

it('forgets the memo after a mutation', function () {
    $user = rbacUser();
    $company = rbacCompany(rbacUser('owner@x.test'));
    $company->addEmployee($user, addedById: $company->created_by_id);

    expect($user->hasPermissionTo('company.settings.view', $company))->toBeFalse();

    $user->assignRole(companyRole('viewer', ['company.settings.view'], $company->id), $company);

    // Mutation reset the memo, so the new grant is visible immediately.
    expect($user->hasPermissionTo('company.settings.view', $company))->toBeTrue();
});
