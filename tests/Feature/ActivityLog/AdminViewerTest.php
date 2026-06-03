<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\ActivityLog\Models\Activity as ActivityModel;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['larafoundry-activitylog.geo.enabled' => false]);
    // The viewer publishes Inertia pages; CI does not ship them on disk.
    config(['inertia.testing.ensure_pages_exist' => false]);
});

function adminUser(): User
{
    return User::create([
        'name' => 'Boss',
        'email' => 'boss@x.test',
        'password' => 'secret-pass',
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
}

function plainUser(): User
{
    return User::create([
        'name' => 'Joe',
        'email' => 'joe@x.test',
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

it('forbids a non-admin from the activity log', function () {
    $this->actingAs(plainUser())
        ->get('/admin/activity-log')
        ->assertForbidden();
});

it('forbids a guest from the activity log', function () {
    $this->get('/admin/activity-log')->assertRedirect();
});

it('lets a super-admin view the global activity log', function () {
    ActivityModel::query()->create(['log_name' => 'Auth', 'description' => 'seeded']);

    $response = $this->actingAs(adminUser())
        ->get('/admin/activity-log', ['X-Inertia' => 'true'])
        ->assertOk();

    $response->assertJsonPath('component', 'Admin/Logs/GeneralLogs')
        ->assertJsonPath('props.selectedHours', 24)
        ->assertJsonCount(1, 'props.logs.data');
    expect($response->json('props.availableHours'))->toBe([1, 6, 12, 24, 48, 72]);
});

it('scopes the per-user view to that user as causer', function () {
    $admin = adminUser();
    $other = plainUser();

    ActivityModel::query()->create(['log_name' => 'Auth', 'description' => 'admin-act', 'causer_id' => $admin->id]);
    ActivityModel::query()->create(['log_name' => 'Auth', 'description' => 'other-act', 'causer_id' => $other->id]);

    $this->actingAs($admin)
        ->get("/admin/activity-log/users/{$other->id}?hours=24", ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Admin/Logs/UserLogs')
        ->assertJsonPath('props.targetUser.id', $other->id)
        ->assertJsonCount(1, 'props.logs.data');
});

it('falls back to the default window for an out-of-range hours filter', function () {
    $this->actingAs(adminUser())
        ->get('/admin/activity-log?hours=999', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.selectedHours', 24);
});

it('honours the admin IP allow-list when configured', function () {
    config(['larafoundry.security.admin_ips' => ['10.0.0.1']]);

    $this->actingAs(adminUser())
        ->get('/admin/activity-log')
        ->assertForbidden();
});
