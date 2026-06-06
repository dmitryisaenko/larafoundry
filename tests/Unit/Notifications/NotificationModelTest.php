<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Notifications\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves a system notification title from its translation key', function () {
    $notification = Notification::create([
        'code' => 'info',
        'notification_type' => 'system',
        'status' => 'sent',
        'title_key' => 'some.title.key',
        'body_key' => 'some.body.key',
    ]);

    // A missing key resolves to itself, which still proves the system branch ran.
    expect($notification->localizedTitle('en'))->toBe('some.title.key')
        ->and($notification->localizedBody('en'))->toBe('some.body.key');
});

it('reads an admin broadcast title per locale and falls back to English', function () {
    $notification = Notification::create([
        'code' => 'info',
        'notification_type' => 'admin',
        'status' => 'sent',
        'title_translations' => ['en' => 'Hello', 'uk' => 'Привіт'],
    ]);

    expect($notification->localizedTitle('uk'))->toBe('Привіт')
        ->and($notification->localizedTitle('en'))->toBe('Hello')
        ->and($notification->localizedTitle('de'))->toBe('Hello'); // fallback
});

it('reports visibility against its window', function () {
    $open = Notification::create(['code' => 'info', 'notification_type' => 'system', 'status' => 'sent']);
    expect($open->isVisible())->toBeTrue();

    $future = Notification::create([
        'code' => 'info', 'notification_type' => 'system', 'status' => 'sent',
        'visible_from' => now()->addDay(),
    ]);
    expect($future->isVisible())->toBeFalse();

    $past = Notification::create([
        'code' => 'info', 'notification_type' => 'system', 'status' => 'sent',
        'visible_until' => now()->subDay(),
    ]);
    expect($past->isVisible())->toBeFalse();
});

it('scopes by type, status and visibility window', function () {
    Notification::create(['code' => 'info', 'notification_type' => 'admin', 'status' => 'draft']);
    Notification::create(['code' => 'info', 'notification_type' => 'system', 'status' => 'sent']);
    Notification::create([
        'code' => 'info', 'notification_type' => 'admin', 'status' => 'sent',
        'visible_until' => now()->subDay(),
    ]);

    expect(Notification::admin()->count())->toBe(2)
        ->and(Notification::system()->count())->toBe(1)
        ->and(Notification::sent()->count())->toBe(2)
        ->and(Notification::visible()->count())->toBe(2); // the expired one is excluded
});
