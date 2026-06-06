<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Notifications\Events\BroadcastNotificationSent;
use Dmitryisaenko\LaraFoundry\Notifications\Jobs\SendBroadcastNotificationJob;
use Dmitryisaenko\LaraFoundry\Notifications\Models\Notification;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function bjUser(string $email, bool $verified = true): User
{
    return User::create([
        'name' => 'U',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => $verified ? now() : null,
    ]);
}

function bjBroadcast(array $filters = []): Notification
{
    return Notification::create([
        'code' => 'info',
        'notification_type' => 'admin',
        'status' => 'sending',
        'title_translations' => ['en' => 'Hi'],
        'recipient_filters' => $filters ?: null,
    ]);
}

it('attaches the filtered audience, excludes the super-admin and marks sent', function () {
    config(['larafoundry.security.super_admin.email' => 'boss@x.test']);

    bjUser('boss@x.test');          // super-admin — must be excluded
    bjUser('v1@x.test');            // verified — in
    bjUser('v2@x.test');            // verified — in
    bjUser('u1@x.test', false);     // unverified — out

    $broadcast = bjBroadcast(['emailVerified' => 'verified']);

    (new SendBroadcastNotificationJob($broadcast->id))->handle();

    $emails = $broadcast->fresh()->users()->pluck('email')->sort()->values()->all();

    expect($emails)->toBe(['v1@x.test', 'v2@x.test'])
        ->and($broadcast->fresh()->status)->toBe('sent');
});

it('only delivers to recently active users when filtered', function () {
    $recent = bjUser('recent@x.test');
    $recent->forceFill(['last_activity_at' => now()->subHour()])->save();

    $stale = bjUser('stale@x.test');
    $stale->forceFill(['last_activity_at' => now()->subDays(10)])->save();

    $broadcast = bjBroadcast(['recentActivity' => '24']);

    (new SendBroadcastNotificationJob($broadcast->id))->handle();

    expect($broadcast->fresh()->users()->pluck('email')->all())->toBe(['recent@x.test']);
});

it('is idempotent — a re-run never double-attaches', function () {
    bjUser('a@x.test');
    bjUser('b@x.test');

    $broadcast = bjBroadcast();

    (new SendBroadcastNotificationJob($broadcast->id))->handle();
    (new SendBroadcastNotificationJob($broadcast->id))->handle();

    $rows = DB::table('larafoundry_notification_user')
        ->where('notification_id', $broadcast->id)
        ->count();

    expect($rows)->toBe(2);
});

it('delivers the whole audience even when chunked small', function () {
    config(['larafoundry-notifications.broadcast.batch_size' => 1]);

    bjUser('a@x.test');
    bjUser('b@x.test');
    bjUser('c@x.test');

    $broadcast = bjBroadcast();

    (new SendBroadcastNotificationJob($broadcast->id))->handle();

    expect($broadcast->fresh()->users()->count())->toBe(3);
});

it('fires the broadcast-sent event', function () {
    Event::fake([BroadcastNotificationSent::class]);

    bjUser('a@x.test');
    $broadcast = bjBroadcast();

    (new SendBroadcastNotificationJob($broadcast->id))->handle();

    Event::assertDispatched(BroadcastNotificationSent::class);
});
