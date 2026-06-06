<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Notifications\Models\Notification;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['larafoundry-notifications.retention.enabled' => true]);
    config(['larafoundry-notifications.retention.read_lifetime_days' => 30]);
});

function pnUser(): User
{
    return User::create([
        'name' => 'U',
        'email' => 'u@x.test',
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

function pnSent(): Notification
{
    return Notification::create(['code' => 'info', 'notification_type' => 'system', 'status' => 'sent']);
}

it('prunes old read notifications but keeps recent and draft ones', function () {
    $user = pnUser();

    $old = pnSent();
    $old->users()->attach($user->id, ['read_at' => now()->subDays(40)]);

    $recent = pnSent();
    $recent->users()->attach($user->id, ['read_at' => now()->subDays(5)]);

    $draft = Notification::create(['code' => 'info', 'notification_type' => 'admin', 'status' => 'draft']);

    $this->artisan('larafoundry:notifications-prune')->assertSuccessful();

    expect(Notification::find($old->id))->toBeNull()       // pivot pruned → orphaned → deleted
        ->and(Notification::find($recent->id))->not->toBeNull()
        ->and(Notification::find($draft->id))->not->toBeNull(); // a draft is never orphan-pruned

    expect(DB::table('larafoundry_notification_user')->where('notification_id', $recent->id)->count())->toBe(1);
});

it('does nothing when retention is disabled', function () {
    config(['larafoundry-notifications.retention.enabled' => false]);

    $user = pnUser();
    $old = pnSent();
    $old->users()->attach($user->id, ['read_at' => now()->subDays(99)]);

    $this->artisan('larafoundry:notifications-prune')->assertSuccessful();

    expect(Notification::find($old->id))->not->toBeNull();
});
