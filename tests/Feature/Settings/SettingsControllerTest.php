<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Settings\Facades\Settings;
use Dmitryisaenko\LaraFoundry\Settings\Models\Setting;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('larafoundry.settings', [
        'a_email' => ['scope' => 'app', 'type' => 'string', 'default' => null, 'validation' => ['nullable', 'email'], 'public' => true],
        'c_tz' => ['scope' => 'company', 'type' => 'string', 'default' => 'UTC', 'validation' => ['string']],
        'u_flag' => ['scope' => 'user', 'type' => 'boolean', 'default' => true, 'validation' => ['boolean']],
        'u_consent' => ['scope' => 'user', 'type' => 'boolean', 'default' => false, 'validation' => ['boolean'], 'form' => false],
    ]);

    // Operator console without the OTP step-up, the flag-only super-admin model.
    config()->set('larafoundry.security.super_admin.require_otp', false);
    config()->set('larafoundry.security.super_admin.email', null);
});

function settingsUser(string $email = 'u@x.test', bool $admin = false): User
{
    return User::create([
        'name' => 'U',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
        'is_admin' => $admin,
    ]);
}

function settingsCompanyOwnedBy(User $owner): Company
{
    $company = Company::create([
        'name' => 'Acme',
        'slug' => 'acme-'.Str::random(6),
        'created_by_id' => $owner->id,
    ]);
    $company->addEmployee($owner, addedById: $owner->id, isOwner: true);

    return $company;
}

// --- Page rendering ---------------------------------------------------------

it('renders the account settings page', function () {
    $user = settingsUser();

    $this->actingAs($user)
        ->withHeader('X-Inertia', 'true')
        ->get('/settings')
        ->assertOk()
        ->assertJsonPath('component', 'Settings/Account');
});

it('renders the company settings page for a privileged member', function () {
    $owner = settingsUser('owner@x.test');
    settingsCompanyOwnedBy($owner);

    $this->actingAs($owner)
        ->withHeader('X-Inertia', 'true')
        ->get('/settings/company')
        ->assertOk()
        ->assertJsonPath('component', 'Settings/Company');
});

it('forbids an unprivileged member from the company settings page', function () {
    $owner = settingsUser('owner@x.test');
    $company = settingsCompanyOwnedBy($owner);
    $member = settingsUser('member@x.test');
    $company->addEmployee($member, addedById: $owner->id);

    $this->actingAs($member)
        ->withHeader('X-Inertia', 'true')
        ->get('/settings/company')
        ->assertForbidden();
});

it('renders the app settings page for a super-admin', function () {
    $admin = settingsUser('boss@x.test', admin: true);

    $this->actingAs($admin)
        ->withHeader('X-Inertia', 'true')
        ->get('/admin/settings')
        ->assertOk()
        ->assertJsonPath('component', 'Admin/Settings');
});

// --- Account (user scope) ---------------------------------------------------

it('lets a user save their own account setting', function () {
    $user = settingsUser();

    $this->actingAs($user)
        ->put('/settings/account', ['key' => 'u_flag', 'value' => false])
        ->assertRedirect();

    $row = Setting::query()->where('scope', 'user')->where('scope_id', (string) $user->id)->where('key', 'u_flag')->first();
    expect($row)->not->toBeNull()
        ->and(Settings::get('u_flag', $user->id))->toBeFalse();
});

it('rejects an out-of-scope key on the account endpoint', function () {
    $user = settingsUser();

    $this->actingAs($user)
        ->put('/settings/account', ['key' => 'a_email', 'value' => 'x@y.test'])
        ->assertSessionHasErrors('key');
});

it('rejects a non-form (consent) key on the account endpoint', function () {
    $user = settingsUser();

    $this->actingAs($user)
        ->put('/settings/account', ['key' => 'u_consent', 'value' => true])
        ->assertSessionHasErrors('key');
});

it('rejects an unregistered key on the account endpoint', function () {
    $user = settingsUser();

    $this->actingAs($user)
        ->put('/settings/account', ['key' => 'nope', 'value' => 1])
        ->assertSessionHasErrors('key');
});

// --- Company scope ----------------------------------------------------------

it('lets a company owner save a company setting scoped to the active company', function () {
    $owner = settingsUser('owner@x.test');
    $company = settingsCompanyOwnedBy($owner);

    $this->actingAs($owner)
        ->put('/settings/company', ['key' => 'c_tz', 'value' => 'Europe/Kyiv'])
        ->assertRedirect();

    // Scope id is the ACTIVE company (resolved server-side), never the request.
    $row = Setting::query()->where('scope', 'company')->where('key', 'c_tz')->first();
    expect($row)->not->toBeNull()
        ->and($row->scope_id)->toBe((string) $company->id)
        ->and(Settings::get('c_tz', $company->id))->toBe('Europe/Kyiv');
});

it('forbids a non-privileged member from writing company settings', function () {
    $owner = settingsUser('owner@x.test');
    $company = settingsCompanyOwnedBy($owner);
    $member = settingsUser('member@x.test');
    $company->addEmployee($member, addedById: $owner->id);

    $this->actingAs($member)
        ->put('/settings/company', ['key' => 'c_tz', 'value' => 'Europe/Kyiv'])
        ->assertForbidden();
});

// --- App scope (super-admin) ------------------------------------------------

it('lets a super-admin save an app setting', function () {
    $admin = settingsUser('boss@x.test', admin: true);

    $this->actingAs($admin)
        ->put('/admin/settings', ['key' => 'a_email', 'value' => 'help@x.test'])
        ->assertRedirect();

    $row = Setting::query()->where('scope', 'app')->where('scope_id', '0')->where('key', 'a_email')->first();
    expect($row)->not->toBeNull()
        ->and(Settings::get('a_email'))->toBe('help@x.test');
});

it('rejects a non-app key on the admin endpoint', function () {
    $admin = settingsUser('boss@x.test', admin: true);

    $this->actingAs($admin)
        ->put('/admin/settings', ['key' => 'u_flag', 'value' => true])
        ->assertSessionHasErrors('key');
});

it('forbids a non-admin from the app settings endpoint', function () {
    $user = settingsUser();

    $this->actingAs($user)
        ->put('/admin/settings', ['key' => 'a_email', 'value' => 'x@y.test'])
        ->assertForbidden();
});
