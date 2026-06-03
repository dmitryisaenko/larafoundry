<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\ActivityLog\Models\Activity as ActivityModel;
use Dmitryisaenko\LaraFoundry\ActivityLog\Support\EventLogRegistry;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyCreated;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('exposes the configured core events', function () {
    $classes = app(EventLogRegistry::class)->eventClasses();

    expect($classes)->toContain(Login::class)
        ->and($classes)->toContain(CompanyCreated::class);
});

it('logs a fired registered event through the subscriber', function () {
    Queue::fake();
    config(['larafoundry-activitylog.geo.enabled' => false]);

    $user = User::create([
        'name' => 'Eve',
        'email' => 'eve@x.test',
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user);

    Event::dispatch(new Login('web', $user, false));

    $entry = ActivityModel::query()->where('event', 'Login')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($user->id)
        ->and($entry->log_name)->toBe('Auth');
});
