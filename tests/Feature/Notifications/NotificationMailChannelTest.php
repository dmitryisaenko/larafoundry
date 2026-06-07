<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Notifications\Jobs\SendBroadcastNotificationJob;
use Dmitryisaenko\LaraFoundry\Notifications\Models\Notification as NotificationModel;
use Dmitryisaenko\LaraFoundry\Notifications\Notifications\InAppMailNotification;
use Dmitryisaenko\LaraFoundry\Notifications\Support\NotificationService;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

/*
| In-app notification mail channel (phase 5.1, decision D-5.1-14): email is an
| OPT-IN per notification, gated by the global `channels` master switch — so it
| sends only when both opt in. Default off: no surprise mass mailings.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('larafoundry-notifications.channels', ['database', 'mail']);
});

function mcUser(string $email = 'u@x.test', string $locale = 'en'): User
{
    return User::create([
        'name' => 'U',
        'email' => $email,
        'password' => 'secret-pass',
        'locale' => $locale,
        'email_verified_at' => now(),
    ]);
}

it('emails system-notification recipients when mail is opted in', function () {
    Notification::fake();

    $a = mcUser('a@x.test');
    $b = mcUser('b@x.test');

    app(NotificationService::class)->system([$a, $b], 'info', 'some.title', mail: true);

    Notification::assertSentTo($a, InAppMailNotification::class);
    Notification::assertSentTo($b, InAppMailNotification::class);
});

it('does NOT email system recipients by default (opt-in)', function () {
    Notification::fake();

    $a = mcUser();

    app(NotificationService::class)->system([$a], 'info', 'some.title');

    Notification::assertNothingSent();
});

it('does NOT email when the master channel switch is off, even if opted in', function () {
    config()->set('larafoundry-notifications.channels', ['database']);
    Notification::fake();

    $a = mcUser();

    app(NotificationService::class)->system([$a], 'info', 'some.title', mail: true);

    Notification::assertNotSentTo($a, InAppMailNotification::class);
});

it('emails the broadcast audience when the broadcast opted in', function () {
    Notification::fake();

    $u = mcUser('v@x.test');

    $broadcast = NotificationModel::create([
        'code' => 'info',
        'notification_type' => 'admin',
        'status' => 'sending',
        'send_mail' => true,
        'title_translations' => ['en' => 'Hi'],
    ]);

    (new SendBroadcastNotificationJob($broadcast->id))->handle();

    Notification::assertSentTo($u, InAppMailNotification::class);
});

it('does NOT email the broadcast audience when not opted in', function () {
    Notification::fake();

    mcUser('v@x.test');

    $broadcast = NotificationModel::create([
        'code' => 'info',
        'notification_type' => 'admin',
        'status' => 'sending',
        'send_mail' => false,
        'title_translations' => ['en' => 'Hi'],
    ]);

    (new SendBroadcastNotificationJob($broadcast->id))->handle();

    Notification::assertNothingSent();
});

it('resolves the mail subject/body in each recipient locale', function () {
    $broadcast = NotificationModel::create([
        'code' => 'info',
        'notification_type' => 'admin',
        'status' => 'sent',
        'title_translations' => ['en' => 'Hello', 'uk' => 'Привіт'],
        'body_translations' => ['en' => 'Body text'],
    ]);

    $en = (new InAppMailNotification($broadcast->id))->toMail(mcUser('en@x.test', 'en'));
    $uk = (new InAppMailNotification($broadcast->id))->toMail(mcUser('uk@x.test', 'uk'));

    expect($en->subject)->toBe('Hello')
        ->and($en->introLines)->toContain('Body text')
        ->and($uk->subject)->toBe('Привіт');
});

it('quietly delivers nothing when its source row is gone', function () {
    $mail = new InAppMailNotification(999999);

    expect($mail->via(mcUser()))->toBe([]);
});
