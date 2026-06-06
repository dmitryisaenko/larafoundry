<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\ActivityLog\Models\Activity as ActivityModel;
use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\ImpersonateController;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['larafoundry-activitylog.geo.enabled' => false]);
    config(['inertia.testing.ensure_pages_exist' => false]);
    // OTP step-up gate is covered in AdminOtpGateTest; run console tests with it off.
    config(['larafoundry.security.super_admin.require_otp' => false]);
});

function superAdmin(string $email = 'root@x.test'): User
{
    return User::create([
        'name' => 'Root',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
}

function plain(string $email = 'user@x.test'): User
{
    return User::create([
        'name' => 'User',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

it('lets a super-admin impersonate a plain user', function () {
    $admin = superAdmin();
    $target = plain();

    $this->actingAs($admin)
        ->post("/admin/impersonate/{$target->id}")
        ->assertRedirect();

    expect(Auth::id())->toBe($target->id)
        ->and(session(ImpersonateController::SESSION_KEY))->toBe($admin->id);
});

it('forbids a non-admin from impersonating', function () {
    $this->actingAs(plain())
        ->post('/admin/impersonate/'.plain('victim@x.test')->id)
        ->assertForbidden();
});

it('refuses to impersonate another admin (escalation guard)', function () {
    $admin = superAdmin();
    $otherAdmin = superAdmin('other-admin@x.test');

    $this->actingAs($admin)
        ->post("/admin/impersonate/{$otherAdmin->id}")
        ->assertForbidden();

    expect(Auth::id())->toBe($admin->id);
});

it('refuses to impersonate yourself', function () {
    $admin = superAdmin();

    $this->actingAs($admin)
        ->post("/admin/impersonate/{$admin->id}")
        ->assertForbidden();
});

it('refuses to impersonate a blocked user', function () {
    $admin = superAdmin();
    $blocked = plain('blocked@x.test');
    $blocked->forceFill(['user_blocked_at' => now()])->save();

    $this->actingAs($admin)
        ->post("/admin/impersonate/{$blocked->id}")
        ->assertForbidden();
});

it('audits both take and leave', function () {
    $admin = superAdmin();
    $target = plain();

    $this->actingAs($admin)->post("/admin/impersonate/{$target->id}")->assertRedirect();
    $this->post('/impersonate/leave')->assertRedirect();

    expect(ActivityModel::where('description', 'admin.impersonate.take')->exists())->toBeTrue()
        ->and(ActivityModel::where('description', 'admin.impersonate.leave')->exists())->toBeTrue();
});

it('returns to the operator account on leave', function () {
    $admin = superAdmin();
    $target = plain();

    $this->actingAs($admin)->post("/admin/impersonate/{$target->id}");
    expect(Auth::id())->toBe($target->id);

    $this->post('/impersonate/leave')->assertRedirect(route('admin.users.index'));

    expect(Auth::id())->toBe($admin->id)
        ->and(session()->has(ImpersonateController::SESSION_KEY))->toBeFalse();
});

it('refuses to nest impersonation', function () {
    $admin = superAdmin();
    $first = plain('first@x.test');
    $second = plain('second@x.test');

    $this->actingAs($admin)->post("/admin/impersonate/{$first->id}")->assertRedirect();

    // Now acting as $first (not an admin) and already impersonating — refuse.
    $this->post("/admin/impersonate/{$second->id}")->assertForbidden();

    expect(Auth::id())->toBe($first->id);
});

it('treats leave without an active impersonation as a no-op redirect', function () {
    $this->actingAs(plain())
        ->post('/impersonate/leave')
        ->assertRedirect();
});

it('records the operator as causer and the target as subject on take', function () {
    $admin = superAdmin();
    $target = plain();

    $this->actingAs($admin)->post("/admin/impersonate/{$target->id}");

    $entry = ActivityModel::where('description', 'admin.impersonate.take')->latest('id')->first();

    expect($entry->causer_id)->toBe($admin->id)
        ->and($entry->subject_id)->toBe($target->id);
});
