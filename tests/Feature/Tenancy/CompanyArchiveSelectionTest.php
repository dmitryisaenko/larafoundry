<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Selection-side guards for owner-driven archiving (phase 7). Unlike the block,
 * archiving is asymmetric: the OWNER may switch into and reach an archived
 * company (to unarchive it), while a non-owner MEMBER is refused. These run
 * against real models with a started session so the active-company round-trip
 * through the resolver is real.
 */

function arcUser(string $email): User
{
    return User::create([
        'name' => 'U', 'email' => $email, 'password' => 'secret-pass',
        'email_verified_at' => now(), // switch route is behind `verified`
    ]);
}

function arcStartSession(): void
{
    $store = new Store('larafoundry_session', new ArraySessionHandler(120), str_repeat('a', 40));
    $store->start();
    app()->instance('session.store', $store);
    request()->setLaravelSession($store);
}

function arcCompany(User $owner, string $name, bool $archived = false): Company
{
    $company = Company::create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.uniqid(),
        'created_by_id' => $owner->id,
    ]);
    $company->addEmployee($owner, addedById: $owner->id, isOwner: true);

    if ($archived) {
        $company->forceFill(['company_archived_at' => now()])->save();
    }

    return $company;
}

it('setNextAvailableCompany skips an archived company and lands on a working one', function () {
    arcStartSession();
    $user = arcUser('arcskip@x.test');
    arcCompany($user, 'Archived', archived: true);
    $good = arcCompany($user, 'Good');

    expect($user->setNextAvailableCompany())->toBeTrue()
        ->and($user->getCurrentCompanyId())->toBe($good->id);
});

it('setNextAvailableCompany returns false when the only company is archived', function () {
    arcStartSession();
    $user = arcUser('arconly@x.test');
    arcCompany($user, 'Only', archived: true);

    expect($user->setNextAvailableCompany())->toBeFalse()
        ->and($user->getActiveCompany())->toBeNull();
});

it('lets the OWNER switch into their archived company to unarchive it', function () {
    $user = arcUser('arcowner@x.test');
    arcCompany($user, 'Good');
    $archived = arcCompany($user, 'Archived', archived: true);

    $this->actingAs($user)
        ->from('/dashboard')
        ->put("/companies/{$archived->uuid}/switch")
        ->assertRedirect()
        ->assertSessionHas('status')
        ->assertSessionMissing('error');
});

it('refuses a NON-owner member switching into an archived company', function () {
    $owner = arcUser('arcowner2@x.test');
    $member = arcUser('arcmember@x.test');
    $archived = arcCompany($owner, 'Archived', archived: true);
    $archived->addEmployee($member, addedById: $owner->id, isOwner: false);

    $this->actingAs($member)
        ->from('/dashboard')
        ->put("/companies/{$archived->uuid}/switch")
        ->assertRedirect()
        ->assertSessionHas('error')
        ->assertSessionMissing('status');
});

it('lets an owner archive their own active company via the archive route', function () {
    $user = arcUser('arcdo@x.test');
    $company = arcCompany($user, 'ToArchive');

    $this->actingAs($user)
        ->from('/dashboard')
        ->put("/companies/{$company->uuid}/archive")
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($company->fresh()->isArchived())->toBeTrue();
});

it('lets an owner unarchive a company via the unarchive route', function () {
    $user = arcUser('arcundo@x.test');
    $company = arcCompany($user, 'ToRestore', archived: true);

    $this->actingAs($user)
        ->from('/dashboard')
        ->put("/companies/{$company->uuid}/unarchive")
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($company->fresh()->isArchived())->toBeFalse();
});

it('forbids a NON-owner member from archiving a company (403, IDOR guard)', function () {
    $owner = arcUser('arcowner3@x.test');
    $member = arcUser('arcmember2@x.test');
    $company = arcCompany($owner, 'Held');
    $company->addEmployee($member, addedById: $owner->id, isOwner: false);

    $this->actingAs($member)
        ->put("/companies/{$company->uuid}/archive")
        ->assertForbidden();

    expect($company->fresh()->isArchived())->toBeFalse();
});

it('forbids archiving a company the user does not belong to (403)', function () {
    $owner = arcUser('arcstranger-owner@x.test');
    $outsider = arcUser('arcstranger@x.test');
    $company = arcCompany($owner, 'Foreign');

    $this->actingAs($outsider)
        ->put("/companies/{$company->uuid}/archive")
        ->assertForbidden();
});

it('forbids a NON-owner member from unarchiving a company (403, IDOR guard)', function () {
    $owner = arcUser('arcunowner@x.test');
    $member = arcUser('arcunmember@x.test');
    $company = arcCompany($owner, 'HeldArchived', archived: true);
    $company->addEmployee($member, addedById: $owner->id, isOwner: false);

    $this->actingAs($member)
        ->put("/companies/{$company->uuid}/unarchive")
        ->assertForbidden();

    // The member could not clear the owner's archive flag.
    expect($company->fresh()->isArchived())->toBeTrue();
});

it('archive is idempotent — a second call leaves the company archived, still succeeds', function () {
    $user = arcUser('arcidem@x.test');
    $company = arcCompany($user, 'Twice', archived: true);
    $stamp = $company->company_archived_at;

    $this->actingAs($user)
        ->from('/dashboard')
        ->put("/companies/{$company->uuid}/archive")
        ->assertRedirect()
        ->assertSessionHas('status');

    // Already-archived: the guard skips the write, so the original timestamp is
    // untouched (no silent re-stamp) and the company stays archived.
    $fresh = $company->fresh();
    expect($fresh->isArchived())->toBeTrue()
        ->and($fresh->company_archived_at->equalTo($stamp))->toBeTrue();
});

it('unarchive is idempotent — a second call on an active company still succeeds', function () {
    $user = arcUser('arcidem2@x.test');
    $company = arcCompany($user, 'NotArchived');

    $this->actingAs($user)
        ->from('/dashboard')
        ->put("/companies/{$company->uuid}/unarchive")
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($company->fresh()->isArchived())->toBeFalse();
});
