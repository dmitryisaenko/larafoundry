<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Dashboard\Support\DashboardMetricsService;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('larafoundry.security.super_admin.require_otp', false);
    config()->set('larafoundry.security.super_admin.email', null);
    config()->set('inertia.testing.ensure_pages_exist', false);
    config()->set('larafoundry-activitylog.geo.enabled', false);
});

function scopeAdmin(): User
{
    return User::create([
        'name' => 'Boss', 'email' => 'boss@x.test', 'password' => 'secret-pass',
        'is_admin' => true, 'email_verified_at' => now(),
    ]);
}

function scopeMember(string $email): User
{
    return User::create(['name' => 'Joe', 'email' => $email, 'password' => 'secret-pass', 'email_verified_at' => now()]);
}

// --- The scope itself -------------------------------------------------------

it('excludes the operator from a withoutSuperAdmin query', function () {
    $admin = scopeAdmin();
    $member = scopeMember('a@x.test');

    $ids = User::query()->withoutSuperAdmin()->pluck('id');

    expect($ids)->toContain($member->id)
        ->and($ids)->not->toContain($admin->id);
});

it('includes the operator when the exclusion is turned off', function () {
    config()->set('larafoundry.admin.exclude_super_admin_from_lists', false);
    $admin = scopeAdmin();

    $ids = User::query()->withoutSuperAdmin()->pluck('id');

    expect($ids)->toContain($admin->id);
});

// --- Type-ahead search ------------------------------------------------------

it('hides the operator from the admin user search', function () {
    $admin = scopeAdmin();
    scopeMember('boss.member@x.test'); // matches the same "boss" term

    $this->actingAs($admin)
        ->getJson('/admin/users/search?search=boss')
        ->assertOk()
        ->assertJsonCount(1, 'users')
        ->assertJsonPath('users.0.email', 'boss.member@x.test');
});

// --- Dashboard aggregates ---------------------------------------------------

it('excludes the operator from the dashboard user totals', function () {
    scopeAdmin();
    scopeMember('a@x.test');
    scopeMember('b@x.test');

    expect(app(DashboardMetricsService::class)->users()['total'])->toBe(2);
});

it('counts the operator in the dashboard totals when the exclusion is off', function () {
    config()->set('larafoundry.admin.exclude_super_admin_from_lists', false);
    scopeAdmin();
    scopeMember('a@x.test');

    expect(app(DashboardMetricsService::class)->users()['total'])->toBe(2);
});

// --- Ticket picker hardening ------------------------------------------------

it('rejects opening an operator-console ticket targeting the operator account', function () {
    $admin = scopeAdmin();

    $this->actingAs($admin)
        ->from('/admin/tickets/create')
        ->post('/admin/tickets', [
            'user_id' => $admin->id,
            'title' => 'Test',
            'message' => 'Hello there',
        ])
        ->assertSessionHasErrors('user_id');
});
