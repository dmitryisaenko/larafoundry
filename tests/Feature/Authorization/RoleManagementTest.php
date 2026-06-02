<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\CreateCompanyAction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Role CRUD over HTTP. The owner of the active company (auto-selected by
 * SetActiveTenant) manages its roles. Headline guards: the server fixes the role
 * flags (no forging a global/cross-company role via mass-assignment), permissions
 * are catalog-constrained, and roles are resolved through the active company
 * (anti-IDOR).
 */

beforeEach(function () {
    rbacSeed();
});

it('lists the active company roles for an owner', function () {
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner);
    Role::create(['name' => 'Viewer', 'slug' => 'viewer', 'is_custom' => true, 'company_id' => $company->id]);

    $this->actingAs($owner)
        ->withHeader('X-Inertia', 'true')
        ->get('/roles')
        ->assertOk();
});

it('lets an owner create a custom company role', function () {
    $owner = rbacUser('owner@x.test');
    rbacCompany($owner);

    $this->actingAs($owner)
        ->post('/roles', [
            'name' => 'Sales',
            'description' => 'Sales team',
            'permissions' => ['company.settings.view'],
        ])
        ->assertRedirect(route('authorization.roles.index'));

    $role = Role::where('slug', 'sales')->first();

    expect($role)->not->toBeNull()
        ->and($role->is_custom)->toBeTrue()
        ->and($role->is_global)->toBeFalse()
        ->and($role->is_template)->toBeFalse()
        ->and($role->permissions->pluck('slug')->all())->toBe(['company.settings.view']);
});

it('SECURITY: ignores forged role flags and company_id (mass-assignment)', function () {
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner);

    $this->actingAs($owner)->post('/roles', [
        'name' => 'Sneaky',
        'is_global' => true,
        'is_template' => true,
        'company_id' => 999999,
    ])->assertRedirect();

    $role = Role::where('slug', 'sneaky')->first();

    expect($role->is_global)->toBeFalse()
        ->and($role->is_template)->toBeFalse()
        ->and((int) $role->company_id)->toBe((int) $company->id);
});

it('SECURITY: rejects permissions outside the catalog', function () {
    $owner = rbacUser('owner@x.test');
    rbacCompany($owner);

    $this->actingAs($owner)
        ->post('/roles', ['name' => 'Bad', 'permissions' => ['orders.nuke']])
        ->assertSessionHasErrors('permissions.0');

    expect(Role::where('slug', 'bad')->exists())->toBeFalse();
});

it('forbids a member without the roles permission from creating roles', function () {
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner);
    $member = rbacUser('member@x.test');
    $company->addEmployee($member, addedById: $owner->id);

    $this->actingAs($member)->post('/roles', ['name' => 'Nope'])->assertForbidden();
});

it('SECURITY: cannot update a role belonging to another company (IDOR)', function () {
    $owner = rbacUser('owner@x.test');
    rbacCompany($owner, 'A');

    $otherOwner = rbacUser('other@x.test');
    $companyB = rbacCompany($otherOwner, 'B');
    $foreignRole = Role::create([
        'name' => 'B-role', 'slug' => 'b-role', 'is_custom' => true, 'company_id' => $companyB->id,
    ]);

    $this->actingAs($owner)
        ->put("/roles/{$foreignRole->id}", ['name' => 'Hijacked'])
        ->assertNotFound();

    expect($foreignRole->fresh()->name)->toBe('B-role');
});

it('refuses to delete a non-custom cloned role', function () {
    $owner = rbacUser('owner@x.test');
    // CreateCompanyAction clones the `member` template (is_custom = false).
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);
    $cloned = Role::where('company_id', $company->id)->where('slug', 'member')->first();

    $this->actingAs($owner)->delete("/roles/{$cloned->id}")->assertForbidden();

    expect($cloned->fresh())->not->toBeNull();
});

it('lets an owner delete an unused custom role', function () {
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner);
    $role = Role::create([
        'name' => 'Temp', 'slug' => 'temp', 'is_custom' => true, 'company_id' => $company->id,
    ]);

    $this->actingAs($owner)->delete("/roles/{$role->id}")->assertRedirect();

    expect(Role::find($role->id))->toBeNull();
});
