<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Notifications\Jobs\SendBroadcastNotificationJob;
use Dmitryisaenko\LaraFoundry\Notifications\Models\Notification;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['inertia.testing.ensure_pages_exist' => false]);
    config(['larafoundry-activitylog.geo.enabled' => false]);
    config(['larafoundry.security.super_admin.require_otp' => false]);
});

function bcAdmin(string $email = 'boss@x.test'): User
{
    return User::create([
        'name' => 'Boss',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
}

function bcUser(string $email = 'joe@x.test'): User
{
    return User::create([
        'name' => 'Joe',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

function bcDraft(): Notification
{
    return Notification::create([
        'code' => 'info',
        'notification_type' => 'admin',
        'status' => 'draft',
        'title_translations' => ['en' => 'Heads up'],
    ]);
}

it('forbids a non-admin from the broadcast console', function () {
    $this->actingAs(bcUser())->get('/admin/notifications')->assertForbidden();
});

it('redirects a guest from the broadcast console', function () {
    $this->get('/admin/notifications')->assertRedirect();
});

it('lets a super-admin list broadcasts', function () {
    bcDraft();

    $this->actingAs(bcAdmin())
        ->get('/admin/notifications', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Admin/Notifications/Index')
        ->assertJsonCount(1, 'props.notifications.data');
});

it('stores a new broadcast as a draft', function () {
    $this->actingAs(bcAdmin())
        ->post('/admin/notifications', [
            'code' => 'info',
            'title_translations' => ['en' => 'Hello'],
            'body_translations' => ['en' => 'Body text'],
        ])
        ->assertRedirect('/admin/notifications');

    expect(Notification::admin()->where('status', 'draft')->count())->toBe(1);
});

it('requires an English title', function () {
    $this->actingAs(bcAdmin())
        ->from('/admin/notifications/create')
        ->post('/admin/notifications', ['code' => 'info', 'title_translations' => ['uk' => 'Привіт']])
        ->assertSessionHasErrors('title_translations.en');
});

it('rejects a visibility window that ends before it starts', function () {
    $this->actingAs(bcAdmin())
        ->from('/admin/notifications/create')
        ->post('/admin/notifications', [
            'code' => 'info',
            'title_translations' => ['en' => 'Hello'],
            'visible_from' => now()->addDay()->toDateTimeString(),
            'visible_until' => now()->toDateTimeString(),
        ])
        ->assertSessionHasErrors('visible_until');
});

it('queues a chunked fan-out when a draft is sent, and refuses to resend', function () {
    Queue::fake();

    $draft = bcDraft();
    $admin = bcAdmin();

    $this->actingAs($admin)
        ->post("/admin/notifications/{$draft->id}/send")
        ->assertRedirect('/admin/notifications');

    expect($draft->fresh()->status)->toBe('sending');
    Queue::assertPushed(SendBroadcastNotificationJob::class, 1);

    // A second send is a no-op: it is no longer a draft, so no new job.
    $this->actingAs($admin)->post("/admin/notifications/{$draft->id}/send");
    Queue::assertPushed(SendBroadcastNotificationJob::class, 1);
});

it('refuses to update a non-draft broadcast', function () {
    $sent = Notification::create([
        'code' => 'info',
        'notification_type' => 'admin',
        'status' => 'sent',
        'title_translations' => ['en' => 'Old'],
    ]);

    $this->actingAs(bcAdmin())
        ->from('/admin/notifications')
        ->put("/admin/notifications/{$sent->id}", [
            'code' => 'info',
            'title_translations' => ['en' => 'New'],
        ])
        ->assertRedirect('/admin/notifications');

    expect($sent->fresh()->title_translations['en'])->toBe('Old');
});

it('deletes a broadcast', function () {
    $draft = bcDraft();

    $this->actingAs(bcAdmin())
        ->delete("/admin/notifications/{$draft->id}")
        ->assertRedirect('/admin/notifications');

    expect(Notification::find($draft->id))->toBeNull();
});
