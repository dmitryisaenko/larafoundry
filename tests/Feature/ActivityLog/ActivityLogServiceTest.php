<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\ActivityLog\Facades\Activity;
use Dmitryisaenko\LaraFoundry\ActivityLog\Jobs\RetrieveActivityGeoData;
use Dmitryisaenko\LaraFoundry\ActivityLog\Models\Activity as ActivityModel;
use Dmitryisaenko\LaraFoundry\ActivityLog\Services\ActivityLogService;
use Dmitryisaenko\LaraFoundry\ActivityLog\Support\ActivityContext;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyCreated;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRemoved;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeUser(string $email = 'actor@x.test'): User
{
    return User::create([
        'name' => 'Actor',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

it('binds spatie activity_model to the core model', function () {
    expect(config('activitylog.activity_model'))->toBe(ActivityModel::class);
});

it('logs a registered event with the actor as causer and no subject for auth', function () {
    Queue::fake();

    $user = makeUser();
    $this->actingAs($user);

    app(ActivityLogService::class)->logEvent(
        event: new Login('web', $user, false),
        eventClassName: 'Login',
        group: 'Auth',
        description: 'User logged in',
        code: 200,
    );

    $entry = ActivityModel::query()->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($user->id)
        ->and($entry->subject_id)->toBeNull()
        ->and($entry->log_name)->toBe('Auth')
        ->and($entry->is_successful)->toBeTrue()
        ->and($entry->user_email)->toBe([$user->email]);
});

it('keeps causer and subject distinct for a domain event', function () {
    Queue::fake();

    $owner = makeUser('owner@x.test');
    $this->actingAs($owner);

    $company = Company::create([
        'name' => 'Acme',
        'slug' => 'acme-'.Str::random(6),
        'created_by_id' => $owner->id,
    ]);

    app(ActivityLogService::class)->logEvent(
        event: new CompanyCreated($company, $owner),
        eventClassName: 'CompanyCreated',
        group: 'Tenancy',
        description: 'Company created',
        code: 201,
    );

    $entry = ActivityModel::query()->latest('id')->first();

    // causer = the owner who acted; subject = the company acted upon. They are
    // different rows of different types — the donor wrote the same id to both.
    expect($entry->causer_id)->toBe($owner->id)
        ->and($entry->causer_type)->toBe($owner->getMorphClass())
        ->and($entry->subject_id)->toBe($company->id)
        ->and($entry->subject_type)->toBe($company->getMorphClass())
        ->and($entry->causer_type)->not->toBe($entry->subject_type);
});

it('records the employee (not the company) as the subject of EmployeeRemoved', function () {
    Queue::fake();

    $owner = makeUser('owner2@x.test');
    $this->actingAs($owner);

    $company = Company::create([
        'name' => 'Acme',
        'slug' => 'acme-'.Str::random(6),
        'created_by_id' => $owner->id,
    ]);
    $employee = makeUser('emp@x.test');

    app(ActivityLogService::class)->logEvent(
        event: new EmployeeRemoved($company, $employee),
        eventClassName: 'EmployeeRemoved',
        group: 'Tenancy',
        description: 'Employee removed',
        code: 200,
    );

    $entry = ActivityModel::query()->latest('id')->first();

    // The object of "Employee removed" is the employee, not the company.
    expect($entry->subject_id)->toBe($employee->id)
        ->and($entry->subject_type)->toBe($employee->getMorphClass())
        ->and($entry->causer_id)->toBe($owner->id);
});

it('captures a candidate email for an event without an authenticated user', function () {
    Queue::fake();

    app(ActivityLogService::class)->logEvent(
        event: new Failed('web', null, ['email' => 'ghost@x.test', 'password' => 'x']),
        eventClassName: 'Failed',
        group: 'Auth',
        description: 'Login failed',
        code: 401,
    );

    $entry = ActivityModel::query()->latest('id')->first();

    expect($entry->causer_id)->toBeNull()
        ->and($entry->user_email)->toBe(['ghost@x.test'])
        ->and($entry->is_successful)->toBeFalse()
        ->and($entry->response_code)->toBe(401);
});

it('dispatches the geo job asynchronously for events when geo is enabled', function () {
    Queue::fake();
    config(['larafoundry-activitylog.geo.enabled' => true]);

    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8']);
    $user = makeUser();
    $this->actingAs($user);

    app(ActivityLogService::class)->logEvent(
        event: new Login('web', $user, false),
        eventClassName: 'Login',
        group: 'Auth',
        description: 'User logged in',
        code: 200,
    );

    Queue::assertPushed(RetrieveActivityGeoData::class);
});

it('does not dispatch the geo job when geo is disabled', function () {
    Queue::fake();
    config(['larafoundry-activitylog.geo.enabled' => false]);

    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8']);
    $user = makeUser();
    $this->actingAs($user);

    app(ActivityLogService::class)->logEvent(
        event: new Login('web', $user, false),
        eventClassName: 'Login',
        group: 'Auth',
        description: 'User logged in',
        code: 200,
    );

    Queue::assertNotPushed(RetrieveActivityGeoData::class);
});

it('writes a manual custom entry via the facade', function () {
    config(['larafoundry-activitylog.geo.enabled' => false]);

    $user = makeUser();
    $this->actingAs($user);

    Activity::log('Did a thing', 'custom', ['note' => 'hello']);

    $entry = ActivityModel::query()->latest('id')->first();

    expect($entry->description)->toBe('Did a thing')
        ->and($entry->log_name)->toBe('custom')
        ->and($entry->causer_id)->toBe($user->id)
        ->and($entry->properties->get('note'))->toBe('hello');
});

it('redacts PII from the stored full_url and properties', function () {
    config(['larafoundry-activitylog.geo.enabled' => false]);

    $user = makeUser();
    $this->actingAs($user);

    // Simulate a request carrying a token in the query string.
    $this->get('/?token=supersecret&page=2');

    Activity::log('Sensitive', 'custom', [
        'password' => 'plaintext',
        'safe' => 'keep-me',
    ]);

    $entry = ActivityModel::query()->latest('id')->first();

    expect($entry->properties->get('password'))->toBe('[redacted]')
        ->and($entry->properties->get('safe'))->toBe('keep-me')
        ->and($entry->full_url)->not->toContain('supersecret')
        ->and($entry->full_url)->toContain('page=2');
});

it('preserves URL fidelity (dotted keys, array params) while redacting PII', function () {
    $context = app(ActivityContext::class);

    $redacted = $context->redactUrl('http://localhost/list?filter.name=acme&ids[]=1&ids[]=2&token=secret');

    // Dotted and array keys keep their exact shape; only the PII value is masked.
    expect($redacted)->toContain('filter.name=acme')
        ->and($redacted)->toContain('ids[]=1')
        ->and($redacted)->toContain('ids[]=2')
        ->and($redacted)->toContain('token=[redacted]')
        ->and($redacted)->not->toContain('secret');
});

it('keeps a relative URL relative when redacting', function () {
    $context = app(ActivityContext::class);

    $redacted = $context->redactUrl('/reset?token=abc&page=2');

    expect($redacted)->toBe('/reset?token=[redacted]&page=2')
        ->and($redacted)->not->toContain('http:///');
});

it('does not mis-join a non-user causer onto the users table', function () {
    $user = makeUser('realuser@x.test'); // becomes users.id = 1

    // A host-style entry whose causer is some OTHER morph type but shares the id.
    ActivityModel::query()->create([
        'log_name' => 'Domain',
        'description' => 'something happened',
        'causer_type' => 'App\\Models\\ApiClient',
        'causer_id' => $user->id,
    ]);

    $entry = ActivityModel::query()->latest('id')->first();

    // user() is constrained to the user morph, so a non-user causer resolves null
    // instead of pulling the unrelated user with the same id.
    expect($entry->user)->toBeNull();
});

it('does not log when the module is disabled', function () {
    config(['larafoundry-activitylog.enabled' => false]);

    $user = makeUser();
    $this->actingAs($user);

    app(ActivityLogService::class)->logEvent(
        event: new Login('web', $user, false),
        eventClassName: 'Login',
        group: 'Auth',
        description: 'User logged in',
        code: 200,
    );

    expect(ActivityModel::query()->count())->toBe(0);
});
