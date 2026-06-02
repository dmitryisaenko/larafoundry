<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Assigning roles / individual permissions to a member of the active company.
 * Guards: the target is resolved through the company's members (anti-IDOR), owners
 * are untouchable, and only the company's own roles can be assigned.
 */

beforeEach(function () {
    rbacSeed();
});

it('lets an owner assign a company role to a member', function () {
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner);
    $member = rbacUser('member@x.test');
    $company->addEmployee($member, addedById: $owner->id);

    $role = Role::create([
        'name' => 'Viewer', 'slug' => 'viewer', 'is_custom' => true, 'company_id' => $company->id,
    ]);
    $role->syncPermissions(['company.settings.view']);

    $this->actingAs($owner)
        ->put("/employees/{$member->id}/roles", ['roles' => [$role->id]])
        ->assertRedirect();

    expect($member->fresh()->hasRole('viewer', $company))->toBeTrue()
        ->and($member->fresh()->hasPermissionTo('company.settings.view', $company))->toBeTrue();
});

it('SECURITY: refuses to modify an owner', function () {
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner);
    $coOwner = rbacUser('co@x.test');
    $company->addEmployee($coOwner, addedById: $owner->id, isOwner: true);

    $role = Role::create([
        'name' => 'Viewer', 'slug' => 'viewer', 'is_custom' => true, 'company_id' => $company->id,
    ]);

    $this->actingAs($owner)
        ->put("/employees/{$coOwner->id}/roles", ['roles' => [$role->id]])
        ->assertForbidden();
});

it('SECURITY: cannot target a user who is not a member (IDOR)', function () {
    $owner = rbacUser('owner@x.test');
    rbacCompany($owner);
    $stranger = rbacUser('stranger@x.test');

    $this->actingAs($owner)
        ->put("/employees/{$stranger->id}/roles", ['roles' => []])
        ->assertNotFound();
});

it('SECURITY: cannot assign a role from another company', function () {
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner, 'A');
    $member = rbacUser('member@x.test');
    $company->addEmployee($member, addedById: $owner->id);

    $otherOwner = rbacUser('other@x.test');
    $companyB = rbacCompany($otherOwner, 'B');
    $foreignRole = Role::create([
        'name' => 'B-role', 'slug' => 'b-role', 'is_custom' => true, 'company_id' => $companyB->id,
    ]);

    $this->actingAs($owner)
        ->put("/employees/{$member->id}/roles", ['roles' => [$foreignRole->id]])
        ->assertSessionHasErrors('roles.0');

    expect($member->fresh()->getRolesInCompany($company))->toHaveCount(0);
});

it('SECURITY: a delegated member cannot grant a permission they do not hold', function () {
    // Regression: `grant_permissions` must not be parlayed into self-escalation.
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner);

    // HR holds only grant_permissions + settings.view (a narrow delegation).
    $hr = rbacUser('hr@x.test');
    $company->addEmployee($hr, addedById: $owner->id);
    $hrRole = Role::create(['name' => 'HR', 'slug' => 'hr', 'is_custom' => true, 'company_id' => $company->id]);
    $hrRole->syncPermissions(['company.employees.grant_permissions', 'company.settings.view']);
    $hr->assignRole($hrRole, $company);

    $target = rbacUser('target@x.test');
    $company->addEmployee($target, addedById: $owner->id);

    // Allowed: grant a permission HR actually holds.
    $this->actingAs($hr)
        ->put("/employees/{$target->id}/permissions", ['grant' => ['company.settings.view']])
        ->assertRedirect();
    expect($target->fresh()->hasPermissionTo('company.settings.view', $company))->toBeTrue();

    // Blocked: grant a permission HR does NOT hold (privilege escalation attempt).
    $this->actingAs($hr)
        ->put("/employees/{$target->id}/permissions", ['grant' => ['company.roles.delete']])
        ->assertForbidden();
    expect($target->fresh()->hasPermissionTo('company.roles.delete', $company))->toBeFalse();
});

it('SECURITY: a delegated member cannot assign a role carrying powers they lack', function () {
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner);

    $hr = rbacUser('hr@x.test');
    $company->addEmployee($hr, addedById: $owner->id);
    $hrRole = Role::create(['name' => 'HR', 'slug' => 'hr', 'is_custom' => true, 'company_id' => $company->id]);
    $hrRole->syncPermissions(['company.employees.assign_role', 'company.settings.view']);
    $hr->assignRole($hrRole, $company);

    // A powerful role HR is NOT entitled to hand out.
    $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin', 'is_custom' => true, 'company_id' => $company->id]);
    $adminRole->syncPermissions(['company.roles.delete']);

    $target = rbacUser('target@x.test');
    $company->addEmployee($target, addedById: $owner->id);

    $this->actingAs($hr)
        ->put("/employees/{$target->id}/roles", ['roles' => [$adminRole->id]])
        ->assertForbidden();

    expect($target->fresh()->hasRole('admin', $company))->toBeFalse();
});

it('lets an owner grant and revoke an individual permission', function () {
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner);
    $member = rbacUser('member@x.test');
    $company->addEmployee($member, addedById: $owner->id);

    $this->actingAs($owner)
        ->put("/employees/{$member->id}/permissions", ['grant' => ['company.settings.view']])
        ->assertRedirect();

    expect($member->fresh()->hasPermissionTo('company.settings.view', $company))->toBeTrue();

    $this->actingAs($owner)
        ->put("/employees/{$member->id}/permissions", ['revoke' => ['company.settings.view']])
        ->assertRedirect();

    expect($member->fresh()->hasPermissionTo('company.settings.view', $company))->toBeFalse();
});
