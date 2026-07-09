<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\ActivityLog\Models\Activity as ActivityModel;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyArchived;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Archiving must reach the activity log (phase 7): the archive controller emits
 * CompanyArchived / CompanyUnarchived, both registered in the activity-log
 * registry (Tenancy group). The events fire only when the state actually flips,
 * so idempotent calls do not double-log.
 */

beforeEach(function () {
    config(['larafoundry-activitylog.geo.enabled' => false]);
});

function alUser(string $email): User
{
    return User::create([
        'name' => 'U', 'email' => $email, 'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

function alCompany(User $owner, string $name, bool $archived = false): Company
{
    $company = Company::create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.uniqid(),
        'created_by_id' => $owner->id,
    ]);
    $company->addEmployee($owner, addedById: $owner->id, isOwner: true);

    if ($archived) {
        $company->forceFill(['company_archived_at' => now()])->save();
    }

    return $company;
}

it('dispatches CompanyArchived when an owner archives', function () {
    Event::fake([CompanyArchived::class]);

    $user = alUser('aldo@x.test');
    $company = alCompany($user, 'ToArchive');

    $this->actingAs($user)->from('/dashboard')->put("/companies/{$company->uuid}/archive");

    Event::assertDispatched(CompanyArchived::class,
        fn ($e) => $e->company->is($company));
});

it('logs the archive to the activity log under the Tenancy group with the uuid', function () {
    $user = alUser('allog@x.test');
    $company = alCompany($user, 'Logged');

    $this->actingAs($user)->from('/dashboard')->put("/companies/{$company->uuid}/archive");

    $entry = ActivityModel::query()->where('event', 'CompanyArchived')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->log_name)->toBe('Tenancy')
        ->and($entry->causer_id)->toBe($user->id)
        ->and($entry->properties['event_properties']['company_uuid'] ?? null)->toBe($company->uuid);
});

it('logs the unarchive under CompanyUnarchived', function () {
    $user = alUser('alun@x.test');
    $company = alCompany($user, 'Restore', archived: true);

    $this->actingAs($user)->from('/dashboard')->put("/companies/{$company->uuid}/unarchive");

    expect(ActivityModel::query()->where('event', 'CompanyUnarchived')->exists())->toBeTrue();
});

it('does not log when archiving an already-archived company (idempotent, no event)', function () {
    $user = alUser('alidem@x.test');
    $company = alCompany($user, 'Already', archived: true);

    $this->actingAs($user)->from('/dashboard')->put("/companies/{$company->uuid}/archive");

    expect(ActivityModel::query()->where('event', 'CompanyArchived')->count())->toBe(0);
});
