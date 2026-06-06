<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Notifications\Models\Notification;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['inertia.testing.ensure_pages_exist' => false]);
    config(['larafoundry-activitylog.geo.enabled' => false]);
});

function nxUser(string $email): User
{
    return User::create([
        'name' => 'U',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

function nxNotify(User $user, string $key = 'k'): Notification
{
    $notification = Notification::create([
        'code' => 'info',
        'notification_type' => 'system',
        'status' => 'sent',
        'title_key' => $key,
    ]);

    $notification->users()->attach($user->id);

    return $notification;
}

it('redirects a guest from the inbox', function () {
    $this->get('/notifications')->assertRedirect();
});

it('shows the user only their own notifications', function () {
    $me = nxUser('me@x.test');
    nxNotify($me, 'a');
    nxNotify($me, 'b');

    $other = nxUser('other@x.test');
    nxNotify($other, 'c');

    $this->actingAs($me)
        ->get('/notifications', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Notifications/Index')
        ->assertJsonCount(2, 'props.notifications.data');
});

it('reports the unread count', function () {
    $me = nxUser('me@x.test');
    nxNotify($me, 'a');
    nxNotify($me, 'b');

    $this->actingAs($me)
        ->getJson('/notifications/unread-count')
        ->assertOk()
        ->assertJson(['unread_count' => 2]);
});

it('returns recent notifications with the unread count for the bell', function () {
    $me = nxUser('me@x.test');
    nxNotify($me, 'a');

    $this->actingAs($me)
        ->getJson('/notifications/recent')
        ->assertOk()
        ->assertJsonPath('unread_count', 1)
        ->assertJsonCount(1, 'notifications');
});

it('marks one of the user own notifications read', function () {
    $me = nxUser('me@x.test');
    $notification = nxNotify($me, 'a');

    $this->actingAs($me)
        ->postJson("/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertJson(['ok' => true, 'unread_count' => 0]);

    $pivot = $me->appNotifications()->where('larafoundry_notifications.id', $notification->id)->first()->pivot;
    expect($pivot->read_at)->not->toBeNull();
});

it('cannot mark another user notification read (IDOR)', function () {
    $me = nxUser('me@x.test');
    $other = nxUser('other@x.test');
    $theirs = nxNotify($other, 'a');

    $this->actingAs($me)
        ->postJson("/notifications/{$theirs->id}/read")
        ->assertNotFound();

    // Still unread for its real owner.
    $pivot = $other->appNotifications()->where('larafoundry_notifications.id', $theirs->id)->first()->pivot;
    expect($pivot->read_at)->toBeNull();
});

it('marks all the user notifications read at once', function () {
    $me = nxUser('me@x.test');
    nxNotify($me, 'a');
    nxNotify($me, 'b');

    $this->actingAs($me)
        ->postJson('/notifications/read-all')
        ->assertOk()
        ->assertJson(['ok' => true, 'marked' => 2, 'unread_count' => 0]);

    expect($me->unreadAppNotificationsCount())->toBe(0);
});
