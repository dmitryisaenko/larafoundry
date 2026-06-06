<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Profile\Actions\DeleteUserAccount;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('soft-deletes the account and purges its sessions', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $user->sessions()->create(['session_id' => str_pad('s', 40, 'a'), 'login_method' => 'native']);
    $this->actingAs($user);

    (new DeleteUserAccount)->delete($user);

    $fresh = $user->fresh();
    expect($fresh->user_deleted_at)->not->toBeNull()
        ->and($fresh->isDeleted())->toBeTrue()
        ->and($user->sessions()->count())->toBe(0);
});

it('refuses to delete an account that still owns a company (no orphan)', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $company = Company::create(['name' => 'Acme', 'slug' => 'acme', 'created_by_id' => $user->getKey()]);
    $company->addEmployee($user, $user->getKey(), isOwner: true);
    $this->actingAs($user);

    expect(fn () => (new DeleteUserAccount)->delete($user))
        ->toThrow(ValidationException::class);

    expect($user->fresh()->user_deleted_at)->toBeNull();
});

it('allows deletion for a non-owner company member', function () {
    $owner = User::create(['name' => 'O', 'email' => 'o@x.test', 'password' => 'secret-pass']);
    $member = User::create(['name' => 'M', 'email' => 'm@x.test', 'password' => 'secret-pass']);
    $company = Company::create(['name' => 'Acme', 'slug' => 'acme', 'created_by_id' => $owner->getKey()]);
    $company->addEmployee($owner, $owner->getKey(), isOwner: true);
    $company->addEmployee($member, $owner->getKey(), isOwner: false);
    $this->actingAs($member);

    (new DeleteUserAccount)->delete($member);

    expect($member->fresh()->user_deleted_at)->not->toBeNull();
});
