<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\ActivityLog\Models\Activity as ActivityModel;
use Dmitryisaenko\LaraFoundry\Auth\Models\UserSession;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Create a company owned by $owner (and optionally add $employee as a member).
 */
function auCompany(User $owner, ?User $employee = null): Company
{
    $company = Company::create([
        'name' => 'Co-'.uniqid(),
        'slug' => Str::slug('co').'-'.uniqid(),
        'created_by_id' => $owner->id,
    ]);

    $company->addEmployee($owner, addedById: $owner->id, isOwner: true);

    if ($employee !== null) {
        $company->addEmployee($employee, addedById: $owner->id);
    }

    return $company;
}

beforeEach(function () {
    config(['larafoundry-activitylog.geo.enabled' => false]);
    config(['inertia.testing.ensure_pages_exist' => false]);
    // OTP step-up gate is covered in AdminOtpGateTest; run console tests with it off.
    config(['larafoundry.security.super_admin.require_otp' => false]);
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
            'password_confirmation' => 'secret-pass',
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
            'password_confirmation' => 'secret-pass',
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

it('exposes locale and derived auth type in the resource', function () {
    $admin = auAdmin();

    $oauth = auMember('oauth@x.test');
    $oauth->forceFill(['locale' => 'uk', 'provider_name' => 'google'])->save();

    $password = auMember('pw@x.test');
    $password->forceFill(['locale' => 'en', 'provider_name' => null])->save();

    $rows = $this->actingAs($admin)
        ->get('/admin/users', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.users.data');

    $byEmail = collect($rows)->keyBy('email');

    expect($byEmail['oauth@x.test'])
        ->locale->toBe('uk')
        ->auth_type->toBe('oauth')
        ->auth_provider->toBe('google');

    expect($byEmail['pw@x.test'])
        ->locale->toBe('en')
        ->auth_type->toBe('password')
        ->auth_provider->toBeNull();
});

it('filters users by exact locale', function () {
    $admin = auAdmin();
    auMember('uk@x.test')->forceFill(['locale' => 'uk'])->save();
    auMember('en@x.test')->forceFill(['locale' => 'en'])->save();

    $this->actingAs($admin)
        ->get('/admin/users?locale=uk', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonCount(1, 'props.users.data')
        ->assertJsonPath('props.users.data.0.email', 'uk@x.test');
});

it('filters users by auth type', function () {
    $admin = auAdmin();
    auMember('social@x.test')->forceFill(['provider_name' => 'github'])->save();
    auMember('local@x.test')->forceFill(['provider_name' => null])->save();

    $oauth = $this->actingAs($admin)
        ->get('/admin/users?authType=oauth', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.users.data');

    expect(collect($oauth)->pluck('email'))->toContain('social@x.test')
        ->not->toContain('local@x.test');
});

it('ignores an unknown authType value instead of collapsing to password', function () {
    $admin = auAdmin();
    auMember('social2@x.test')->forceFill(['provider_name' => 'github'])->save();
    auMember('local2@x.test')->forceFill(['provider_name' => null])->save();

    // A stale/crafted value must be a no-op (like status/registered), not a
    // silent whereNull that hides every OAuth user.
    $rows = $this->actingAs($admin)
        ->get('/admin/users?authType=garbage', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.users.data');

    expect(collect($rows)->pluck('email'))
        ->toContain('social2@x.test')
        ->toContain('local2@x.test');
});

it('ships an empty extra_columns by default (host seam)', function () {
    $admin = auAdmin();
    auMember();

    $first = $this->actingAs($admin)
        ->get('/admin/users', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.users.data.0');

    expect($first['extra_columns'])->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Phase 3a — opt-in personal columns, split company counts, verify actions
|--------------------------------------------------------------------------
*/

it('keeps phone/sex/age out of the payload when no columns are opted in', function () {
    config(['larafoundry.admin.user_columns' => []]);
    $admin = auAdmin();
    auMember('pii@x.test')->forceFill([
        'phone' => '+100000000',
        'sex' => 'm',
        'birth_date' => now()->subYears(30),
    ])->save();

    $first = $this->actingAs($admin)
        ->get('/admin/users', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.users.data.0');

    expect($first)->not->toHaveKey('phone')
        ->and($first)->not->toHaveKey('sex')
        ->and($first)->not->toHaveKey('age');

    // And the table is told to render none of them.
    $this->actingAs($admin)
        ->get('/admin/users', ['X-Inertia' => 'true'])
        ->assertJsonPath('props.userColumns', []);
});

it('emits phone/sex/age only for the opted-in tokens and computes age from birth_date', function () {
    config(['larafoundry.admin.user_columns' => ['phone', 'sex', 'age']]);
    $admin = auAdmin();
    auMember('pii@x.test')->forceFill([
        'phone' => '+100000000',
        'sex' => 'f',
        'birth_date' => now()->subYears(42)->subDays(3),
    ])->save();

    $row = collect(
        $this->actingAs($admin)
            ->get('/admin/users', ['X-Inertia' => 'true'])
            ->assertOk()
            ->json('props.users.data')
    )->firstWhere('email', 'pii@x.test');

    expect($row['phone'])->toBe('+100000000')
        ->and($row['sex'])->toBe('f')
        ->and($row['age'])->toBe(42);
});

it('sanitises an unknown user_columns token out of the prop and payload', function () {
    config(['larafoundry.admin.user_columns' => ['phone', 'evil', 'age']]);
    $admin = auAdmin();
    auMember('pii@x.test')->forceFill(['phone' => '+1', 'birth_date' => now()->subYears(20)])->save();

    $response = $this->actingAs($admin)
        ->get('/admin/users', ['X-Inertia' => 'true'])
        ->assertOk();

    expect($response->json('props.userColumns'))->toBe(['phone', 'age']);

    $first = collect($response->json('props.users.data'))->firstWhere('email', 'pii@x.test');
    expect($first)->toHaveKey('phone')
        ->and($first)->toHaveKey('age')
        ->and($first)->not->toHaveKey('sex');
});

it('emits a null age when the opted-in user has no birth date', function () {
    config(['larafoundry.admin.user_columns' => ['age']]);
    $admin = auAdmin();
    auMember('nodob@x.test'); // birth_date stays null

    $row = collect(
        $this->actingAs($admin)
            ->get('/admin/users', ['X-Inertia' => 'true'])
            ->json('props.users.data')
    )->firstWhere('email', 'nodob@x.test');

    expect($row)->toHaveKey('age')
        ->and($row['age'])->toBeNull();
});

it('counts owned and employee companies separately', function () {
    $admin = auAdmin();
    $owner = auMember('owner@x.test');
    $worker = auMember('worker@x.test');

    // owner owns 2 companies; worker is an employee in both.
    auCompany($owner, $worker);
    auCompany($owner, $worker);

    $rows = collect(
        $this->actingAs($admin)
            ->get('/admin/users', ['X-Inertia' => 'true'])
            ->json('props.users.data')
    )->keyBy('email');

    expect($rows['owner@x.test']['owned_companies_count'])->toBe(2)
        ->and($rows['owner@x.test']['employee_companies_count'])->toBe(0)
        ->and($rows['worker@x.test']['owned_companies_count'])->toBe(0)
        ->and($rows['worker@x.test']['employee_companies_count'])->toBe(2);
});

it('filters users by exact country', function () {
    $admin = auAdmin();
    auMember('pl@x.test')->forceFill(['country' => 'PL'])->save();
    auMember('ua@x.test')->forceFill(['country' => 'UA'])->save();

    $this->actingAs($admin)
        ->get('/admin/users?country=PL', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonCount(1, 'props.users.data')
        ->assertJsonPath('props.users.data.0.email', 'pl@x.test');
});

it('filters users by phone verification', function () {
    $admin = auAdmin();
    auMember('verified@x.test')->forceFill(['phone_verified_at' => now()])->save();
    auMember('unverified@x.test')->forceFill(['phone_verified_at' => null])->save();

    $this->actingAs($admin)
        ->get('/admin/users?phoneVerified=verified', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonCount(1, 'props.users.data')
        ->assertJsonPath('props.users.data.0.email', 'verified@x.test');
});

it('filters users by exact sex', function () {
    $admin = auAdmin();
    auMember('m@x.test')->forceFill(['sex' => 'm'])->save();
    auMember('f@x.test')->forceFill(['sex' => 'f'])->save();

    $this->actingAs($admin)
        ->get('/admin/users?sex=f', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonCount(1, 'props.users.data')
        ->assertJsonPath('props.users.data.0.email', 'f@x.test');
});

it('filters users by age range bucket', function () {
    $admin = auAdmin();
    auMember('young@x.test')->forceFill(['birth_date' => now()->subYears(22)])->save();
    auMember('mid@x.test')->forceFill(['birth_date' => now()->subYears(40)])->save();
    auMember('old@x.test')->forceFill(['birth_date' => now()->subYears(70)])->save();

    $emails = collect(
        $this->actingAs($admin)
            ->get('/admin/users?ageRange=36-45', ['X-Inertia' => 'true'])
            ->assertOk()
            ->json('props.users.data')
    )->pluck('email');

    expect($emails)->toContain('mid@x.test')
        ->not->toContain('young@x.test')
        ->not->toContain('old@x.test');
});

it('treats the open-ended 60+ age bucket as a lower bound only', function () {
    $admin = auAdmin();
    auMember('mid@x.test')->forceFill(['birth_date' => now()->subYears(40)])->save();
    auMember('old@x.test')->forceFill(['birth_date' => now()->subYears(72)])->save();

    $emails = collect(
        $this->actingAs($admin)
            ->get('/admin/users?ageRange=60%2B', ['X-Inertia' => 'true'])
            ->assertOk()
            ->json('props.users.data')
    )->pluck('email');

    expect($emails)->toContain('old@x.test')
        ->not->toContain('mid@x.test');
});

it('ignores an unknown age bucket instead of hiding everyone', function () {
    $admin = auAdmin();
    auMember('a@x.test')->forceFill(['birth_date' => now()->subYears(30)])->save();
    auMember('b@x.test')->forceFill(['birth_date' => now()->subYears(50)])->save();

    // admin + 2 members = 3 rows; a garbage bucket is a no-op.
    $this->actingAs($admin)
        ->get('/admin/users?ageRange=garbage', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonCount(3, 'props.users.data');
});

it('force-verifies a user email and logs it', function () {
    $admin = auAdmin();
    $target = auMember();
    $target->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($admin)
        ->post("/admin/users/{$target->id}/verify-email")
        ->assertRedirect();

    expect($target->fresh()->email_verified_at)->not->toBeNull();
    expect(ActivityModel::where('description', 'admin.user.email_verified')->exists())->toBeTrue();
});

it('clears a user email verification and logs it', function () {
    $admin = auAdmin();
    $target = auMember();
    $target->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($admin)
        ->post("/admin/users/{$target->id}/unverify-email")
        ->assertRedirect();

    expect($target->fresh()->email_verified_at)->toBeNull();
    expect(ActivityModel::where('description', 'admin.user.email_unverified')->exists())->toBeTrue();
});

it('force-verifies a user phone and logs it', function () {
    $admin = auAdmin();
    $target = auMember();

    $this->actingAs($admin)
        ->post("/admin/users/{$target->id}/verify-phone")
        ->assertRedirect();

    expect($target->fresh()->phone_verified_at)->not->toBeNull();
    expect(ActivityModel::where('description', 'admin.user.phone_verified')->exists())->toBeTrue();
});

it('clears a user phone verification and logs it', function () {
    $admin = auAdmin();
    $target = auMember();
    $target->forceFill(['phone_verified_at' => now()])->save();

    $this->actingAs($admin)
        ->post("/admin/users/{$target->id}/unverify-phone")
        ->assertRedirect();

    expect($target->fresh()->phone_verified_at)->toBeNull();
    expect(ActivityModel::where('description', 'admin.user.phone_unverified')->exists())->toBeTrue();
});

it('persists the block reason passed from the dialog', function () {
    $admin = auAdmin();
    $target = auMember();

    $this->actingAs($admin)
        ->post("/admin/users/{$target->id}/block", [
            'reason' => 'Spamming the support inbox',
            'block_code' => 7,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('users', [
        'id' => $target->id,
        'user_blocked_status' => 'Spamming the support inbox',
        'block_code' => 7,
    ]);

    expect($target->fresh()->user_blocked_at)->not->toBeNull();
});

it('scopes the per-user activity log (Logs row action target) to that user', function () {
    $admin = auAdmin();
    $one = auMember('one@x.test');
    $two = auMember('two@x.test');

    // The "Logs" row action links to admin.activity-log.user, scoped by causer.
    ActivityModel::query()->create(['log_name' => 'Auth', 'description' => 'one-act', 'causer_id' => $one->id]);
    ActivityModel::query()->create(['log_name' => 'Auth', 'description' => 'two-act', 'causer_id' => $two->id]);

    $descriptions = collect(
        $this->actingAs($admin)
            ->get("/admin/activity-log/users/{$one->id}", ['X-Inertia' => 'true'])
            ->assertOk()
            ->json('props.logs.data')
    )->pluck('description');

    expect($descriptions)->toContain('one-act')
        ->not->toContain('two-act');
});

it('always ships phone to the edit form even under the default privacy-clean config', function () {
    // Regression: gating phone in the shared resource must not strip it from the
    // single-user edit view, or the form prefills blank and a save wipes it.
    config(['larafoundry.admin.user_columns' => []]);
    $admin = auAdmin();
    $target = auMember('editme@x.test');
    $target->forceFill(['phone' => '+100000000'])->save();

    $user = $this->actingAs($admin)
        ->get("/admin/users/{$target->id}/edit", ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.user');

    $data = $user['data'] ?? $user;

    expect($data['phone'])->toBe('+100000000');
});

it('does not wipe a stored phone when editing under the default config', function () {
    config(['larafoundry.admin.user_columns' => []]);
    $admin = auAdmin();
    $target = auMember('keepphone@x.test');
    $target->forceFill(['phone' => '+100000000'])->save();

    // The form (now prefilled from the resource) submits the real phone back.
    $this->actingAs($admin)
        ->put("/admin/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'phone' => '+100000000',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('users', [
        'id' => $target->id,
        'phone' => '+100000000',
    ]);
});

it('preselects the user on the admin ticket create form', function () {
    $admin = auAdmin();
    $target = auMember('cust@x.test');

    $this->actingAs($admin)
        ->get("/admin/tickets/create?user={$target->id}", ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.preselectedUser.id', $target->id)
        ->assertJsonPath('props.preselectedUser.email', 'cust@x.test');
});

it('exposes legacy-parity resource fields: blocked/deleted "ago" text and stale activity', function () {
    config(['larafoundry.admin.active_within_days' => 30]);
    $admin = auAdmin();

    $blocked = auMember('parity-blocked@x.test');
    $blocked->forceFill([
        'user_blocked_at' => now()->subDays(3),
        'last_activity_at' => now()->subDays(90),
    ])->save();

    $fresh = auMember('parity-fresh@x.test');
    $fresh->forceFill(['last_activity_at' => now()])->save();

    $rows = collect(
        $this->actingAs($admin)
            ->get('/admin/users', ['X-Inertia' => 'true'])
            ->assertOk()
            ->json('props.users.data')
    )->keyBy('email');

    expect($rows['parity-blocked@x.test']['is_blocked'])->toBeTrue()
        ->and($rows['parity-blocked@x.test']['blocked_at_human'])->toBeString()
        ->and($rows['parity-blocked@x.test']['last_activity_stale'])->toBeTrue()
        ->and($rows['parity-fresh@x.test']['last_activity_stale'])->toBeFalse()
        ->and($rows['parity-fresh@x.test']['blocked_at_human'])->toBeNull()
        ->and($rows['parity-fresh@x.test']['deleted_at_human'])->toBeNull();
});
