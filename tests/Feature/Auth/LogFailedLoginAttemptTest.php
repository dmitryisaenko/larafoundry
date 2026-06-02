<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Notifications\AdminLoginAttemptNotification;
use Illuminate\Auth\Events\Failed;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    config()->set('larafoundry.auth.failed_login.notify_admin', true);
    config()->set('larafoundry.auth.failed_login.admin_email', 'admin@x.test');
});

it('notifies the admin when a failed attempt targets the admin email', function () {
    event(new Failed('web', null, ['email' => 'admin@x.test', 'password' => 'nope']));

    Notification::assertSentTo(
        new AnonymousNotifiable,
        AdminLoginAttemptNotification::class,
        function ($notification, $channels, $notifiable) {
            return $notifiable->routes['mail'] === 'admin@x.test'
                && $notification->step === 'failed';
        }
    );
});

it('does not notify when the failed attempt targets a different email', function () {
    event(new Failed('web', null, ['email' => 'someone@x.test', 'password' => 'nope']));

    Notification::assertNothingSent();
});

it('does not notify when admin notifications are disabled', function () {
    config()->set('larafoundry.auth.failed_login.notify_admin', false);

    event(new Failed('web', null, ['email' => 'admin@x.test', 'password' => 'nope']));

    Notification::assertNothingSent();
});
