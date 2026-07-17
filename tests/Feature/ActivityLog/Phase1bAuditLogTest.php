<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\ActivityLog\Models\Activity as ActivityModel;
use Dmitryisaenko\LaraFoundry\ActivityLog\Support\EventLogRegistry;
use Dmitryisaenko\LaraFoundry\Auth\Actions\UpdateUserPassword;
use Dmitryisaenko\LaraFoundry\Auth\Actions\UpdateUserProfileInformation;
use Dmitryisaenko\LaraFoundry\Auth\Events\AdminAccessAttemptFailed;
use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Dmitryisaenko\LaraFoundry\Media\Events\FileUploaded;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
 * Phase 1b (activity completeness): RBAC role CRUD, self-service profile/password
 * edits, the previously-dead-lettered admin-access + file-upload events, and the
 * admin-editable console screens (legal / email) must all reach the activity log.
 * Uses the global rbac* helpers for the role/company setup.
 */

beforeEach(function () {
    config(['larafoundry-activitylog.geo.enabled' => false]);
    config(['inertia.testing.ensure_pages_exist' => false]);
    config(['larafoundry.security.super_admin.require_otp' => false]);
    rbacSeed();
});

// --- RBAC role CRUD ---

it('logs RoleCreated when an owner creates a role', function () {
    $owner = rbacUser('rc-owner@x.test');
    rbacCompany($owner);

    $this->actingAs($owner)->post('/roles', ['name' => 'Sales'])->assertRedirect();

    $entry = ActivityModel::query()->where('event', 'RoleCreated')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->log_name)->toBe('Authorization')
        ->and($entry->causer_id)->toBe($owner->id)
        ->and($entry->properties['event_properties']['role_name'] ?? null)->toBe('Sales');
});

it('logs RoleUpdated when an owner edits a role', function () {
    $owner = rbacUser('ru-owner@x.test');
    $company = rbacCompany($owner);
    $role = Role::create(['name' => 'Viewer', 'slug' => 'viewer', 'is_custom' => true, 'company_id' => $company->id]);

    $this->actingAs($owner)
        ->put(route('authorization.roles.update', $role->id), ['name' => 'Viewer 2'])
        ->assertRedirect();

    expect(ActivityModel::query()->where('event', 'RoleUpdated')->exists())->toBeTrue();
});

it('logs RoleDeleted when an owner deletes an unused role', function () {
    $owner = rbacUser('rd-owner@x.test');
    $company = rbacCompany($owner);
    $role = Role::create(['name' => 'Temp', 'slug' => 'temp', 'is_custom' => true, 'company_id' => $company->id]);

    $this->actingAs($owner)
        ->delete(route('authorization.roles.destroy', $role->id))
        ->assertRedirect();

    expect(ActivityModel::query()->where('event', 'RoleDeleted')->exists())->toBeTrue();
});

// --- Self-service profile / password ---

it('logs ProfileUpdated on a self profile edit', function () {
    $user = rbacUser('prof@x.test');
    $this->actingAs($user);

    (new UpdateUserProfileInformation)->update($user, [
        'name' => 'Renamed',
        'email' => $user->email,
    ]);

    $entry = ActivityModel::query()->where('event', 'ProfileUpdated')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($user->id)
        ->and($entry->properties['event_properties']['email_changed'] ?? null)->toBeFalse();
});

it('logs PasswordUpdated on a self password change', function () {
    $user = rbacUser('pw@x.test', ['password' => 'secret-pass']);
    $this->actingAs($user);

    (new UpdateUserPassword)->update($user, [
        'current_password' => 'secret-pass',
        'password' => 'new-secret-pass',
        'password_confirmation' => 'new-secret-pass',
    ]);

    expect(ActivityModel::query()->where('event', 'PasswordUpdated')->exists())->toBeTrue();
});

// --- Previously dead-lettered events ---

it('logs the previously-unregistered AdminAccessAttemptFailed', function () {
    Event::dispatch(new AdminAccessAttemptFailed(step: 'password', ip: '203.0.113.5', userAgent: 'x'));

    $entry = ActivityModel::query()->where('event', 'AdminAccessAttemptFailed')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->log_name)->toBe('Auth')
        ->and($entry->properties['event_properties']['step'] ?? null)->toBe('password');
});

it('registers the previously-dead-lettered FileUploaded event', function () {
    expect(app(EventLogRegistry::class)->eventClasses())->toContain(FileUploaded::class);
});

// --- Admin-editable console screens (manual Activity::log) ---

function p1bAdmin(): User
{
    return User::create([
        'name' => 'Boss', 'email' => 'p1b-boss@x.test', 'password' => 'secret-pass',
        'email_verified_at' => now(), 'is_admin' => true,
    ]);
}

it('audits an admin legal-page update', function () {
    $this->actingAs(p1bAdmin())
        ->put('/admin/legal-pages/terms', [
            'title' => ['en' => 'Terms', 'uk' => 'Умови'],
            'body_html' => ['en' => '<p>Hi</p>', 'uk' => '<p>Привіт</p>'],
            'is_published' => true,
        ])
        ->assertRedirect();

    expect(ActivityModel::query()->where('description', 'admin.legal_page.updated')->exists())->toBeTrue();
});

it('audits an admin email-template update', function () {
    $this->actingAs(p1bAdmin())
        ->put('/admin/email-templates/welcome_email', [
            'is_active' => true,
            'subject' => ['en' => 'Hi', 'uk' => 'Привіт'],
            'body_html' => ['en' => '<p>Hi</p>', 'uk' => '<p>Привіт</p>'],
            'body_text' => ['en' => 'Hi', 'uk' => 'Привіт'],
        ])
        ->assertRedirect();

    $entry = ActivityModel::query()->where('description', 'admin.email_template.updated')->latest('id')->first();
    expect($entry)->not->toBeNull()
        // Must be stored readably, NOT masked by the 'code' pii_redact_keys entry.
        ->and($entry->properties['template_code'] ?? null)->toBe('welcome_email');
});
