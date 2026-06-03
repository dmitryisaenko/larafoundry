<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\ActivityLog\Models\Activity as ActivityModel;
use Dmitryisaenko\LaraFoundry\Auth\Models\UserSession;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['larafoundry-activitylog.geo.enabled' => false]);
    config(['inertia.testing.ensure_pages_exist' => false]);
});

function auAdmin(string $email = 'boss@x.test'): User
{
    return User::create([
        'name' => 'Boss',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
}

function auMember(string $email = 'joe@x.test'): User
{
    return User::create([
        'name' => 'Joe',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

it('forbids a non-admin from the user list', function () {
    $this->actingAs(auMember())->get('/admin/users')->assertForbidden();
});

it('redirects a guest from the user list', function () {
    $this->get('/admin/users')->assertRedirect();
});

it('lets a super-admin list users', function () {
    $admin = auAdmin();
    auMember('a@x.test');
    auMember('b@x.test');

    $this->actingAs($admin)
        ->get('/admin/users', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Admin/Users/Index')
        ->assertJsonCount(3, 'props.users.data');
});

it('filters the list by status', function () {
    $admin = auAdmin();
    $blocked = auMember('blocked@x.test');
    $blocked->forceFill(['user_blocked_at' => now()])->save();
    auMember('active@x.test');

    $this->actingAs($admin)
        ->get('/admin/users?status=blocked', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonCount(1, 'props.users.data')
        ->assertJsonPath('props.users.data.0.email', 'blocked@x.test');
});

it('searches users by name or email', function () {
    $admin = auAdmin();
    auMember('needle@x.test');
    auMember('haystack@x.test');

    $this->actingAs($admin)
        ->getJson('/admin/users/search?search=needle')
        ->assertOk()
        ->assertJsonCount(1, 'users')
        ->assertJsonPath('users.0.email', 'needle@x.test');
});

it('creates a user', function () {
    $this->actingAs(auAdmin())
        ->post('/admin/users', [
            'name' => 'New',
            'email' => 'new@x.test',
            'password' => 'secret-pass',
        ])
        ->assertRedirect();

    expect(User::where('email', 'new@x.test')->exists())->toBeTrue();
});

it('does not let the admin flag be granted unless explicitly set', function () {
    $this->actingAs(auAdmin())
        ->post('/admin/users', [
            'name' => 'Plain',
            'email' => 'plain@x.test',
            'password' => 'secret-pass',
        ]);

    expect(User::where('email', 'plain@x.test')->first()->is_admin)->toBeFalse();
});

it('updates a user profile', function () {
    $admin = auAdmin();
    $target = auMember();

    $this->actingAs($admin)
        ->put("/admin/users/{$target->id}", [
            'name' => 'Renamed',
            'email' => $target->email,
        ])
        ->assertRedirect();

    expect($target->fresh()->name)->toBe('Renamed');
});

it('blocks a user, kills their sessions and logs it', function () {
    $admin = auAdmin();
    $target = auMember();

    UserSession::create([
        'user_id' => $target->id,
        'session_id' => 'sess-1',
        'last_activity' => now(),
    ]);

    $this->actingAs($admin)
        ->post("/admin/users/{$target->id}/block", ['block_code' => 3])
        ->assertRedirect();

    $fresh = $target->fresh();

    expect($fresh->user_blocked_at)->not->toBeNull()
        ->and($fresh->block_code)->toBe(3)
        ->and(UserSession::where('user_id', $target->id)->count())->toBe(0);

    expect(ActivityModel::where('description', 'admin.user.blocked')->exists())->toBeTrue();
});

it('clamps an out-of-range block_code to null instead of overflowing the column', function () {
    $admin = auAdmin();
    $target = auMember();

    // block_code is unsignedTinyInteger (0-255); 999 must not reach the column.
    $this->actingAs($admin)
        ->post("/admin/users/{$target->id}/block", ['block_code' => 999])
        ->assertRedirect();

    $fresh = $target->fresh();
    expect($fresh->user_blocked_at)->not->toBeNull()
        ->and($fresh->block_code)->toBeNull();
});

it('unblocks a user', function () {
    $admin = auAdmin();
    $target = auMember();
    $target->forceFill(['user_blocked_at' => now(), 'block_code' => 2])->save();

    $this->actingAs($admin)
        ->post("/admin/users/{$target->id}/unblock")
        ->assertRedirect();

    expect($target->fresh()->user_blocked_at)->toBeNull();
});

it('soft-deletes and restores a user', function () {
    $admin = auAdmin();
    $target = auMember();

    $this->actingAs($admin)->delete("/admin/users/{$target->id}")->assertRedirect();
    expect($target->fresh()->user_deleted_at)->not->toBeNull();

    $this->actingAs($admin)->post("/admin/users/{$target->id}/restore")->assertRedirect();
    expect($target->fresh()->user_deleted_at)->toBeNull();
});

it('paginates the list at the configured page size', function () {
    config(['larafoundry.admin.users_per_page' => 2]);
    $admin = auAdmin();
    auMember('a@x.test');
    auMember('b@x.test');
    auMember('c@x.test');

    $this->actingAs($admin)
        ->get('/admin/users', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.pagination.per_page', 2)
        ->assertJsonCount(2, 'props.users.data');
});

it('omits social links from the admin user resource', function () {
    $admin = auAdmin();
    auMember();

    $response = $this->actingAs($admin)
        ->get('/admin/users', ['X-Inertia' => 'true'])
        ->assertOk();

    $first = $response->json('props.users.data.0');

    expect($first)->not->toHaveKey('provider_token')
        ->and($first)->not->toHaveKey('provider_name');
});
