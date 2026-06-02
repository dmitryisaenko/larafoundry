<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Authorization\Jobs\CloneCompanyRolesJob;
use Dmitryisaenko\LaraFoundry\Authorization\Models\Permission;
use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Authorization\Support\PermissionCatalog;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\CreateCompanyAction;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\RemoveEmployeeAction;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Catalog seeding (the sync command), the `authenticated` role auto-assignment on
 * registration, and template cloning on company creation — the wiring that makes
 * RBAC self-bootstrapping.
 */

it('syncs the catalog into the database idempotently', function () {
    rbacSeed();

    expect(Permission::count())->toBe(count(app(PermissionCatalog::class)->slugs()))
        ->and(Role::where('slug', 'authenticated')->where('is_global', true)->exists())->toBeTrue()
        ->and(Role::where('slug', 'member')->where('is_template', true)->exists())->toBeTrue();

    $permissionCount = Permission::count();
    $roleCount = Role::count();

    // Re-running must not duplicate rows.
    rbacSeed();

    expect(Permission::count())->toBe($permissionCount)
        ->and(Role::count())->toBe($roleCount);
});

it('gives the authenticated global role its catalog permissions', function () {
    rbacSeed();

    $role = Role::where('slug', 'authenticated')->first();

    expect($role->permissions->pluck('slug'))
        ->toContain('profile.view')
        ->toContain('companies.create');
});

it('assigns the authenticated role to a newly registered user', function () {
    rbacSeed();
    $user = rbacUser();

    event(new Registered($user));

    expect($user->fresh()->hasRole('authenticated'))->toBeTrue();
});

it('does not fail registration when the catalog is not yet synced', function () {
    // No rbacSeed(): the authenticated role does not exist. Registration must
    // still succeed (the listener skips silently).
    $user = rbacUser();

    event(new Registered($user));

    expect($user->fresh()->getGlobalRoles())->toHaveCount(0);
});

it('clones role templates into a new company on creation', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');

    // CreateCompanyAction fires CompanyCreated → CloneCompanyRoles listener →
    // CloneCompanyRolesJob (runs inline on the sync queue).
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);

    $cloned = Role::where('company_id', $company->id)->get();

    expect($cloned)->toHaveCount(1)
        ->and($cloned->first()->slug)->toBe('member')
        ->and($cloned->first()->is_template)->toBeFalse()
        ->and($cloned->first()->is_global)->toBeFalse()
        ->and($cloned->first()->permissions->pluck('slug'))->toContain('company.settings.view');
});

it('revokes a removed member\'s company roles, freeing the role for deletion', function () {
    // Regression (code-review): removing a member soft-deletes their company_user
    // row but must also strip their RBAC grants — otherwise a custom role they held
    // could never reach zero holders and stay permanently undeletable, and the
    // stale assignment would linger if they were ever re-resolved in the company.
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $company = rbacCompany($owner);
    $member = rbacUser('member@x.test');
    $company->addEmployee($member, addedById: $owner->id);

    $role = Role::create(['name' => 'Viewer', 'slug' => 'viewer', 'is_custom' => true, 'company_id' => $company->id]);
    $member->assignRole($role, $company);

    expect($role->fresh()->canBeDeleted())->toBeFalse();

    app(RemoveEmployeeAction::class)->execute($company, $member);

    expect($member->fresh()->getRolesInCompany($company))->toHaveCount(0)
        ->and($role->fresh()->canBeDeleted())->toBeTrue();
});

it('clones idempotently (a repeated job does not duplicate roles)', function () {
    rbacSeed();
    $owner = rbacUser('owner@x.test');
    $company = app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);

    // Dispatch the job again directly: it must no-op because the company already
    // has roles.
    (new CloneCompanyRolesJob($company))->handle();

    expect(Role::where('company_id', $company->id)->count())->toBe(1);
});
