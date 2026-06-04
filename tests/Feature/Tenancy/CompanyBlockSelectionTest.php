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
 * The selection-side guards that keep a blocked company (phase 3.3) from
 * BECOMING or staying active, so the reactive cascade (EnsureActiveTenant) is not
 * the only line of defence: setNextAvailableCompany skips blocked companies, and
 * the switcher refuses to switch into one. These run against real models with a
 * started session so the active-company round-trip through the resolver is real.
 */

function selUser(string $email): User
{
    return User::create([
        'name' => 'U', 'email' => $email, 'password' => 'secret-pass',
        'email_verified_at' => now(), // switch route is behind `verified`
    ]);
}

function selStartSession(): void
{
    $store = new Store('larafoundry_session', new ArraySessionHandler(120), str_repeat('a', 40));
    $store->start();
    app()->instance('session.store', $store);
    request()->setLaravelSession($store);
}

function selCompany(User $owner, string $name, bool $blocked = false): Company
{
    $company = Company::create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.uniqid(),
        'created_by_id' => $owner->id,
    ]);
    $company->addEmployee($owner, addedById: $owner->id, isOwner: true);

    if ($blocked) {
        $company->forceFill(['company_blocked_at' => now()])->save();
    }

    return $company;
}

it('setNextAvailableCompany skips a blocked company and lands on a working one', function () {
    selStartSession();
    $user = selUser('skip@x.test');
    selCompany($user, 'Blocked', blocked: true);
    $good = selCompany($user, 'Good');

    expect($user->setNextAvailableCompany())->toBeTrue()
        ->and($user->getCurrentCompanyId())->toBe($good->id);
});

it('setNextAvailableCompany returns false when the only company is blocked', function () {
    selStartSession();
    $user = selUser('allblocked@x.test');
    selCompany($user, 'Only', blocked: true);

    expect($user->setNextAvailableCompany())->toBeFalse()
        ->and($user->getActiveCompany())->toBeNull();
});

it('refuses to switch into a blocked company with an error flash', function () {
    $user = selUser('switcher@x.test');
    selCompany($user, 'Good');
    $blocked = selCompany($user, 'Blocked', blocked: true);

    $this->actingAs($user)
        ->from('/dashboard')
        ->put("/companies/{$blocked->uuid}/switch")
        ->assertRedirect()
        ->assertSessionHas('error');
});

it('allows switching into an unblocked company with a status flash', function () {
    $user = selUser('switcher2@x.test');
    selCompany($user, 'First');
    $second = selCompany($user, 'Second');

    $this->actingAs($user)
        ->from('/dashboard')
        ->put("/companies/{$second->uuid}/switch")
        ->assertRedirect()
        ->assertSessionHas('status')
        ->assertSessionMissing('error');
});
