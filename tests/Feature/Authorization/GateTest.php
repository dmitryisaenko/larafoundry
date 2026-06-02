<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/*
 * The catalog permissions are exposed as gates, with a single Gate::before for
 * super-admins. The permission gate resolves the user's ACTIVE company, so a
 * call site like `Gate::allows('company.roles.view')` needs no context.
 */

beforeEach(function () {
    rbacSeed();
});

it('short-circuits every ability for a super admin via Gate::before', function () {
    $admin = rbacUser('admin@x.test', ['is_admin' => true]);

    // No active company, no roles — Gate::before still says yes.
    expect(Gate::forUser($admin)->allows('company.roles.delete'))->toBeTrue();
});

it('resolves the active company when checking a permission gate', function () {
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner);
    $member = rbacUser('member@x.test');
    $company->addEmployee($member, addedById: $owner->id);

    $role = Role::create([
        'name' => 'Viewer', 'slug' => 'viewer', 'is_custom' => true, 'company_id' => $company->id,
    ]);
    $role->syncPermissions(['company.roles.view']);
    $member->assignRole($role, $company);

    rbacActivate($member, $company);

    expect(Gate::forUser($member)->allows('company.roles.view'))->toBeTrue()
        ->and(Gate::forUser($member)->allows('company.roles.delete'))->toBeFalse();
});

it('denies a permission gate when there is no active company', function () {
    $member = rbacUser('member@x.test');

    // No active company resolved → the permission gate has no company context and
    // the member holds no global grant for it.
    expect(Gate::forUser($member)->allows('company.roles.view'))->toBeFalse();
});
