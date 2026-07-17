<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Models\UserSession;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Flag-only super-admin, OTP step-up off — these tests exercise the profile
    // surface, not identity resolution or the OTP gate (covered elsewhere).
    config()->set('larafoundry.security.super_admin.require_otp', false);
    config()->set('larafoundry.security.super_admin.email', null);
    config()->set('inertia.testing.ensure_pages_exist', false);
});

function profileAdmin(): User
{
    return User::create([
        'name' => 'Boss',
        'email' => 'boss@x.test',
        'password' => 'secret-pass',
        'is_admin' => true,
        'email_verified_at' => now(),
    ]);
}

// --- Access -----------------------------------------------------------------

it('renders the operator profile hub for a super-admin', function () {
    $this->actingAs(profileAdmin())
        ->get('/admin/profile', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Admin/Profile/Hub')
        // ProfileResource is wrapped in `data` as an Inertia prop in the package
        // test env (the host unwraps); the Vue side reads it flat like the tenant
        // hub does.
        ->assertJsonPath('props.profile.data.email', 'boss@x.test');
});

it('opens the tab named in the ?tab query', function () {
    $this->actingAs(profileAdmin())
        ->get('/admin/profile?tab=security', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.initialTab', 'security');
});

it('falls back to the profile tab for an unknown ?tab', function () {
    $this->actingAs(profileAdmin())
        ->get('/admin/profile?tab=bogus', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.initialTab', 'profile');
});

it('forbids a non-admin from the operator profile hub', function () {
    $member = User::create([
        'name' => 'Member', 'email' => 'm@x.test', 'password' => 'secret-pass', 'email_verified_at' => now(),
    ]);

    $this->actingAs($member)->get('/admin/profile')->assertForbidden();
});

// --- Profile information (email is immutable) --------------------------------

it('updates the operator profile fields and persists the full payload', function () {
    $admin = profileAdmin();

    $this->actingAs($admin)
        ->put('/admin/profile/information', [
            'name' => 'Renamed',
            'lastname' => 'Ivanov',
            'middlename' => 'Petrovich',
            'phone' => '+380001112233',
            'country' => 'UA',
            'sex' => 'm',
            'birth_date' => '1990-05-01',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas($admin->getTable(), [
        'id' => $admin->id,
        'name' => 'Renamed',
        'lastname' => 'Ivanov',
        'middlename' => 'Petrovich',
        'phone' => '+380001112233',
        'country' => 'UA',
        'sex' => 'm',
        'email' => 'boss@x.test',
    ]);
});

it('never changes the operator email even if one is submitted', function () {
    $admin = profileAdmin();

    $this->actingAs($admin)
        ->put('/admin/profile/information', [
            'name' => 'Boss',
            'email' => 'hijack@x.test',
        ])
        ->assertRedirect();

    expect($admin->fresh()->email)->toBe('boss@x.test');
});

it('validates the required name on the operator profile', function () {
    $this->actingAs(profileAdmin())
        ->from('/admin/profile')
        ->put('/admin/profile/information', ['name' => ''])
        ->assertRedirect('/admin/profile')
        ->assertSessionHasErrors('name');
});

// --- Avatar -----------------------------------------------------------------

it('stores and removes the operator avatar', function () {
    Storage::fake('public');
    $admin = profileAdmin();

    $this->actingAs($admin)
        ->post('/admin/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg', 400, 400)])
        ->assertRedirect();

    $stored = $admin->fresh()->avatar;
    expect($stored)->not->toBeNull();
    Storage::disk('public')->assertExists($stored);

    $this->actingAs($admin)->delete('/admin/profile/avatar')->assertRedirect();

    expect($admin->fresh()->avatar)->toBeNull();
    Storage::disk('public')->assertMissing($stored);
});

// --- Preferences (ui_settings) trimmed to the core appearance keys -----------

it('exposes only the core appearance ui_settings to the operator', function () {
    // Simulate a host that registered an extra app toggle at runtime — it must
    // NOT leak into the operator Preferences tab (allowlist is fail-closed).
    config()->set('larafoundry.profile.ui_settings.host_widget', [
        'type' => 'boolean', 'default' => false, 'label' => 'Host widget',
    ]);

    $keys = ['theme', 'sidebar_collapsed', 'date_format', 'time_format'];

    $this->actingAs(profileAdmin())
        ->get('/admin/profile', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.uiSettings.host_widget', null)
        ->assertJsonPath('props.uiSettings.theme', 'system')
        ->assertJsonPath('props.uiSettingsSchema', function (array $schema) use ($keys) {
            $schemaKeys = array_column($schema, 'key');
            sort($schemaKeys);
            sort($keys);

            return $schemaKeys === $keys;
        });
});

// --- Sessions ---------------------------------------------------------------

it('revokes one of the operator own tracked sessions', function () {
    $admin = profileAdmin();
    $other = $admin->sessions()->create(['session_id' => str_pad('oth', 40, 'b'), 'login_method' => 'native']);

    $this->actingAs($admin)
        ->delete("/admin/profile/sessions/{$other->id}")
        ->assertRedirect();

    expect(UserSession::find($other->id))->toBeNull();
});

it('does not revoke another user session through the operator endpoint (IDOR)', function () {
    $admin = profileAdmin();
    $victim = User::create(['name' => 'V', 'email' => 'v@x.test', 'password' => 'secret-pass']);
    $foreign = $victim->sessions()->create(['session_id' => str_pad('vic', 40, 'c'), 'login_method' => 'native']);

    $this->actingAs($admin)
        ->delete("/admin/profile/sessions/{$foreign->id}")
        ->assertNotFound();

    expect(UserSession::find($foreign->id))->not->toBeNull();
});

it('signs out the operator other devices', function () {
    $admin = profileAdmin();
    $admin->sessions()->create(['session_id' => str_pad('one', 40, 'a'), 'login_method' => 'native']);
    $admin->sessions()->create(['session_id' => str_pad('two', 40, 'b'), 'login_method' => 'native']);

    $this->actingAs($admin)
        ->delete('/admin/profile/sessions/others')
        ->assertRedirect();
});

// --- Password change delegates to the operator security endpoint -------------

it('changes the operator password through the security endpoint', function () {
    $admin = profileAdmin();

    $this->actingAs($admin)
        ->put('/admin/security/password', [
            'current_password' => 'secret-pass',
            'password' => 'New-Str0ng-Passw0rd!',
            'password_confirmation' => 'New-Str0ng-Passw0rd!',
        ])
        ->assertRedirect(route('admin.profile.show', ['tab' => 'security']));

    expect(Hash::check('New-Str0ng-Passw0rd!', $admin->fresh()->password))->toBeTrue();
});
