<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Notifications\Models\Notification;
use Dmitryisaenko\LaraFoundry\Notifications\Support\NotificationService;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function nsUser(string $email): User
{
    return User::create([
        'name' => 'U',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

it('creates a system notification and attaches the given users', function () {
    $a = nsUser('a@x.test');
    $b = nsUser('b@x.test');

    $notification = app(NotificationService::class)->system(
        [$a, $b],
        'info',
        'orders.shipped.title',
        'orders.shipped.body',
        ['order' => '42'],
        ['actions' => [['label' => 'View', 'url' => '/orders/42']]],
    );

    expect($notification->notification_type)->toBe('system')
        ->and($notification->status)->toBe('sent')
        ->and($notification->users()->count())->toBe(2)
        ->and($a->appNotifications()->count())->toBe(1)
        ->and($a->unreadAppNotificationsCount())->toBe(1);
});

it('accepts ids and de-duplicates recipients', function () {
    $a = nsUser('a@x.test');

    $notification = app(NotificationService::class)->system(
        [$a->id, $a->id],
        'info',
        'some.title',
    );

    expect($notification->users()->count())->toBe(1);
});

it('records the record a notification is about and finds it by that record', function () {
    $reader = nsUser('a@x.test');
    $order = nsUser('subject@x.test'); // any model serves as the subject

    $service = app(NotificationService::class);
    $about = $service->system([$reader], 'info', 'orders.shipped.title', subject: $order);
    $service->system([$reader], 'info', 'orders.other.title');

    expect($about->subject_type)->toBe($order->getMorphClass())
        ->and($about->subject_id)->toBe($order->id)
        ->and($about->fresh()->subject->is($order))->toBeTrue()
        ->and(Notification::query()->about($order)->pluck('id')->all())->toBe([$about->id]);
});

it('leaves the subject empty when none is given', function () {
    $notification = app(NotificationService::class)->system([nsUser('a@x.test')], 'info', 'some.title');

    expect($notification->fresh()->subject_type)->toBeNull()
        ->and($notification->fresh()->subject_id)->toBeNull();
});
