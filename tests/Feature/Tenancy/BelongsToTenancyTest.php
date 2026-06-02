<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * The company-membership behaviour the BelongsToTenancy trait adds to the user
 * model: which companies they belong to, ownership checks, and picking the next
 * available company. Active-company selection routes through the resolver and is
 * covered by TenantResolverTest; here we test the relations and predicates.
 */

function tenantUser(string $email = 'u@x.test'): User
{
    return User::create(['name' => 'U', 'email' => $email, 'password' => 'secret-pass']);
}

function makeCompany(User $owner, string $name = 'Acme'): Company
{
    $company = Company::create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.uniqid(),
        'created_by_id' => $owner->id,
    ]);

    $company->addEmployee($owner, addedById: $owner->id, isOwner: true);

    return $company;
}

it('lists the companies a user belongs to', function () {
    $user = tenantUser();
    makeCompany($user, 'One');
    makeCompany($user, 'Two');

    expect($user->companies()->count())->toBe(2);
});

it('separates owned from employee companies', function () {
    $owner = tenantUser('owner@x.test');
    $member = tenantUser('member@x.test');

    $company = makeCompany($owner, 'Owned');
    $company->addEmployee($member, addedById: $owner->id);

    expect($owner->ownedCompanies()->count())->toBe(1)
        ->and($owner->employeeCompanies()->count())->toBe(0)
        ->and($member->ownedCompanies()->count())->toBe(0)
        ->and($member->employeeCompanies()->count())->toBe(1);
});

it('excludes soft-removed memberships from companies()', function () {
    $owner = tenantUser('owner@x.test');
    $member = tenantUser('member@x.test');
    $company = makeCompany($owner, 'Co');
    $company->addEmployee($member, addedById: $owner->id);

    expect($member->companies()->count())->toBe(1);

    $company->removeEmployee($member);

    expect($member->fresh()->companies()->count())->toBe(0);
});

it('knows whether a user owns a company', function () {
    $owner = tenantUser('owner@x.test');
    $member = tenantUser('member@x.test');
    $company = makeCompany($owner, 'Co');
    $company->addEmployee($member, addedById: $owner->id);

    expect($owner->isOwnerOf($company))->toBeTrue()
        ->and($member->isOwnerOf($company))->toBeFalse();
});

it('re-adding a soft-removed member restores the membership', function () {
    $owner = tenantUser('owner@x.test');
    $member = tenantUser('member@x.test');
    $company = makeCompany($owner, 'Co');
    $company->addEmployee($member, addedById: $owner->id);
    $company->removeEmployee($member);

    expect($member->fresh()->companies()->count())->toBe(0);

    $company->addEmployee($member, addedById: $owner->id);

    expect($member->fresh()->companies()->count())->toBe(1);
});

it('SECURITY: a soft-removed ex-owner re-invited as a member does NOT return as owner', function () {
    // Privilege-resurrection guard: removing an owner clears is_owner on the
    // dormant row, so re-adding them as a plain member (addEmployee defaults
    // isOwner=false) must NOT silently restore ownership via the owner-sticky OR.
    $owner = tenantUser('owner@x.test');
    $exOwner = tenantUser('ex@x.test');
    $company = makeCompany($owner, 'Co');
    $company->addEmployee($exOwner, addedById: $owner->id, isOwner: true);

    expect($exOwner->isOwnerOf($company))->toBeTrue();

    $company->removeEmployee($exOwner);
    $company->addEmployee($exOwner, addedById: $owner->id); // re-invited as a member

    expect($exOwner->fresh()->isOwnerOf($company))->toBeFalse()
        ->and($exOwner->fresh()->companies()->count())->toBe(1);
});

it('never demotes an existing owner when re-added with isOwner=false', function () {
    // Regression: re-accepting an invite (addEmployee defaults isOwner=false)
    // must not strip ownership from someone already an owner.
    $owner = tenantUser('owner@x.test');
    $company = makeCompany($owner, 'Co'); // owner attached as is_owner=true

    // Re-add as a plain member (what InvitationController::accept does).
    $company->addEmployee($owner, addedById: $owner->id, isOwner: false);

    expect($owner->fresh()->isOwnerOf($company))->toBeTrue();
});
