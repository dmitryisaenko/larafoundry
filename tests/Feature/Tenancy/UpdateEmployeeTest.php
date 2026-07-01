<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\CreateCompanyAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * Edit-employee (owner-only): an owner updates a member's identity (name, lastname,
 * avatar) and roles — never email/password. SECURITY spine: owner-only, the target
 * must be a MEMBER of the owner's active company (not the owner, not a stranger),
 * and every role must belong to that company. Uses the global rbac* helpers.
 */

beforeEach(fn () => Notification::fake());

it('lets an owner update a member name, lastname and roles', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);
    $member = Role::where('company_id', $company->id)->where('slug', 'member')->firstOrFail();

    $worker = rbacUser('worker@x.test');
    $company->addEmployee($worker, addedById: $owner->id, isOwner: false);

    $this->actingAs($owner)->post("/employees/{$worker->id}", [
        'name' => 'Grace',
        'lastname' => 'Hopper',
        'manage_roles' => true,
        'role_ids' => [$member->id],
    ])->assertRedirect();

    $worker = $worker->fresh();

    expect($worker->name)->toBe('Grace')
        ->and($worker->lastname)->toBe('Hopper')
        ->and($worker->hasRole('member', $company))->toBeTrue();
});

it('replaces the role set: unchecking all removes them', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);
    $member = Role::where('company_id', $company->id)->where('slug', 'member')->firstOrFail();

    $worker = rbacUser('worker@x.test');
    $company->addEmployee($worker, addedById: $owner->id);
    $worker->assignRole($member, $company);

    $this->actingAs($owner)->post("/employees/{$worker->id}", [
        'name' => 'Worker',
        'manage_roles' => true,
        'role_ids' => [],
    ])->assertRedirect();

    expect($worker->fresh()->getRolesInCompany($company))->toHaveCount(0);
});

it('leaves roles untouched when manage_roles is not set (partial edit)', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);
    $member = Role::where('company_id', $company->id)->where('slug', 'member')->firstOrFail();

    $worker = rbacUser('worker@x.test');
    $company->addEmployee($worker, addedById: $owner->id);
    $worker->assignRole($member, $company);

    // An update that changes only the name and does NOT opt into role management
    // must not wipe the existing roles (guard against silent role loss).
    $this->actingAs($owner)->post("/employees/{$worker->id}", [
        'name' => 'Renamed',
    ])->assertRedirect();

    expect($worker->fresh()->hasRole('member', $company))->toBeTrue();
});

it('uploads an avatar and can remove it', function () {
    Storage::fake('public');
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);

    $worker = rbacUser('worker@x.test');
    $company->addEmployee($worker, addedById: $owner->id);

    $this->actingAs($owner)->post("/employees/{$worker->id}", [
        'name' => 'Worker',
        'avatar' => UploadedFile::fake()->image('a.jpg', 300, 300),
    ])->assertRedirect();

    $stored = $worker->fresh()->avatar;
    expect($stored)->not->toBeNull()->toContain('avatars');
    Storage::disk('public')->assertExists($stored);

    $this->actingAs($owner)->post("/employees/{$worker->id}", [
        'name' => 'Worker',
        'remove_avatar' => true,
    ])->assertRedirect();

    expect($worker->fresh()->avatar)->toBeNull();
    Storage::disk('public')->assertMissing($stored);
});

it('rejects a non-image avatar', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);

    $worker = rbacUser('worker@x.test');
    $company->addEmployee($worker, addedById: $owner->id);

    $this->actingAs($owner)->post("/employees/{$worker->id}", [
        'name' => 'Worker',
        'avatar' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'),
    ])->assertSessionHasErrors('avatar');
});

it('SECURITY: a non-owner member cannot update another member', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);

    $worker = rbacUser('worker@x.test');
    $company->addEmployee($worker, addedById: $owner->id);
    $other = rbacUser('other@x.test');
    $company->addEmployee($other, addedById: $owner->id);

    $this->actingAs($other)->post("/employees/{$worker->id}", [
        'name' => 'Hacked',
    ])->assertForbidden();

    expect($worker->fresh()->name)->not->toBe('Hacked');
});

it('SECURITY: the owner is not editable via this path', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);

    $this->actingAs($owner)->post("/employees/{$owner->id}", [
        'name' => 'Renamed',
    ])->assertForbidden();
});

it('SECURITY: cannot edit a user who is not a member (IDOR)', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);

    // A user who belongs to ANOTHER company, not the owner's active one.
    $stranger = rbacUser('stranger@x.test');
    rbacCompany($stranger, 'Beta');

    $this->actingAs($owner)->post("/employees/{$stranger->id}", [
        'name' => 'X',
    ])->assertNotFound();
});

it('SECURITY: rejects a role_id that is not a company role', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);

    $worker = rbacUser('worker@x.test');
    $company->addEmployee($worker, addedById: $owner->id);

    $globalRole = Role::where('slug', 'authenticated')->where('is_global', true)->firstOrFail();

    $this->actingAs($owner)->post("/employees/{$worker->id}", [
        'name' => 'Worker',
        'role_ids' => [$globalRole->id],
    ])->assertSessionHasErrors('role_ids.0');
});
