<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\ActivityLog\Models\Activity as ActivityModel;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\AuditableNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['larafoundry-activitylog.geo.enabled' => false]);
});

it('records a created entry for an auditable model', function () {
    AuditableNote::create(['body' => 'first']);

    $entry = ActivityModel::query()->where('description', 'created')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->subject_type)->toBe((new AuditableNote)->getMorphClass());
});

it('records an updated entry with a real old to new diff', function () {
    $note = AuditableNote::create(['body' => 'before']);

    $note->update(['body' => 'after']);

    $entry = ActivityModel::query()->where('description', 'updated')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties->get('attributes')['body'])->toBe('after')
        ->and($entry->properties->get('old')['body'])->toBe('before');
});

it('decorates audit entries with the core HTTP context', function () {
    // Put a request carrying a known UA / IP into the container so the trait's
    // ActivityContext reads it (a model save is not itself an HTTP roundtrip).
    $request = Request::create('/notes', 'POST', server: [
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120',
        'REMOTE_ADDR' => '203.0.113.5',
    ]);
    app()->instance('request', $request);

    AuditableNote::create(['body' => 'with-context']);

    $entry = ActivityModel::query()->where('description', 'created')->latest('id')->first();

    expect($entry->user_ip)->toBe('203.0.113.5')
        ->and($entry->user_browser)->toBe('Chrome')
        ->and($entry->is_successful)->toBeTrue();
});

it('does not submit an empty log on a no-op save', function () {
    $note = AuditableNote::create(['body' => 'x']);
    $before = ActivityModel::query()->count();

    // Saving without dirtying anything should produce no new audit row.
    $note->save();

    expect(ActivityModel::query()->count())->toBe($before);
});
