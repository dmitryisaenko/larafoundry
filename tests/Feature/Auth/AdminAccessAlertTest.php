<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Events\AdminAccessAttemptFailed;
use Dmitryisaenko\LaraFoundry\Auth\Http\Controllers\PinController;
use Dmitryisaenko\LaraFoundry\Auth\Listeners\AlertOnAdminOtpFailure;
use Dmitryisaenko\LaraFoundry\Auth\Models\UserSession;
use Dmitryisaenko\LaraFoundry\Auth\Notifications\AdminLoginAttemptNotification;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('larafoundry.auth.failed_login.notify_admin', true);
    config()->set('larafoundry.auth.failed_login.admin_email', 'admin@x.test');
    config()->set('larafoundry.auth.failed_login.alert_on', ['password', 'lockout', 'admin_otp', 'pin']);
    config()->set('larafoundry.auth.failed_login.channels', ['mail']);
    config()->set('larafoundry.auth.failed_login.notification', null);
    // The geo resolver is network-bound; the alert path never calls it, but the
    // PIN failure logs an activity row whose geo sync we keep off in tests.
    config()->set('larafoundry-activitylog.geo.enabled', false);
});

/** A super-admin matching the configured admin email. */
function alertAdmin(): User
{
    return User::create([
        'name' => 'Boss',
        'email' => 'admin@x.test',
        'password' => 'secret-pass',
        'is_admin' => true,
        'email_verified_at' => now(),
    ]);
}

/** A 40-char session id from a short seed. */
function alertSid(string $seed): string
{
    return str_pad((string) preg_replace('/[^a-z0-9]/', '', $seed), 40, '0');
}

function alertPinRequest(User $user, string $sessionId, array $params): Request
{
    $store = new Store('larafoundry_session', new ArraySessionHandler(120), alertSid($sessionId));
    $store->start();

    $request = Request::create('/dashboard', 'POST', $params);
    $request->setLaravelSession($store);
    $request->setUserResolver(fn () => $user);

    return $request;
}

function alertPinSession(User $user, string $sessionId): UserSession
{
    return UserSession::create([
        'user_id' => $user->getKey(),
        'session_id' => alertSid($sessionId),
        'login_method' => 'native',
        'last_activity' => now(),
        'pin_locked' => true,
    ]);
}

// --- Event: raised on the three failure sources for the super-admin ---------

it('raises the unified event on a password failure targeting the admin', function () {
    Event::fake([AdminAccessAttemptFailed::class]);

    event(new Failed('web', null, ['email' => 'admin@x.test', 'password' => 'nope']));

    Event::assertDispatched(
        AdminAccessAttemptFailed::class,
        fn (AdminAccessAttemptFailed $e) => $e->step === 'password' && $e->email === 'admin@x.test',
    );
});

it('does not raise the event when the password failure targets a different email', function () {
    Event::fake([AdminAccessAttemptFailed::class]);

    event(new Failed('web', null, ['email' => 'someone@x.test', 'password' => 'nope']));

    Event::assertNotDispatched(AdminAccessAttemptFailed::class);
});

it('raises the event on a super-admin OTP failure', function () {
    Event::fake([AdminAccessAttemptFailed::class]);
    $admin = alertAdmin();

    app(AlertOnAdminOtpFailure::class)->handle(new TwoFactorAuthenticationFailed($admin));

    Event::assertDispatched(
        AdminAccessAttemptFailed::class,
        fn (AdminAccessAttemptFailed $e) => $e->step === 'admin_otp',
    );
});

it('does NOT raise the event on an ordinary user OTP failure (login-2FA)', function () {
    Event::fake([AdminAccessAttemptFailed::class]);
    $user = User::create(['name' => 'U', 'email' => 'u@x.test', 'password' => 'secret-pass']);

    app(AlertOnAdminOtpFailure::class)->handle(new TwoFactorAuthenticationFailed($user));

    Event::assertNotDispatched(AdminAccessAttemptFailed::class);
});

it('raises the event on a super-admin PIN failure', function () {
    Event::fake([AdminAccessAttemptFailed::class]);
    $admin = alertAdmin();
    $admin->forceFill(['pin_code' => Hash::make('1234')])->save();
    Auth::login($admin);
    alertPinSession($admin, 'sess-admin');

    expect(fn () => (new PinController)->check(alertPinRequest($admin, 'sess-admin', ['pin' => '0000'])))
        ->toThrow(ValidationException::class);

    Event::assertDispatched(
        AdminAccessAttemptFailed::class,
        fn (AdminAccessAttemptFailed $e) => $e->step === 'pin',
    );
});

it('does NOT raise the event on an ordinary user PIN failure', function () {
    Event::fake([AdminAccessAttemptFailed::class]);
    $user = User::create(['name' => 'U', 'email' => 'u@x.test', 'password' => 'secret-pass']);
    $user->forceFill(['pin_code' => Hash::make('1234')])->save();
    Auth::login($user);
    alertPinSession($user, 'sess-user');

    expect(fn () => (new PinController)->check(alertPinRequest($user, 'sess-user', ['pin' => '0000'])))
        ->toThrow(ValidationException::class);

    Event::assertNotDispatched(AdminAccessAttemptFailed::class);
});

// --- Mail listener: sends only when policy allows ---------------------------

it('mails the admin on a password failure', function () {
    Notification::fake();

    event(new Failed('web', null, ['email' => 'admin@x.test', 'password' => 'nope']));

    Notification::assertSentTo(
        new AnonymousNotifiable,
        AdminLoginAttemptNotification::class,
        fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'admin@x.test' && $n->step === 'password',
    );
});

it('mails the recipient resolved from security.super_admin.email when admin_email is null', function () {
    // Deployment sets ONLY the canonical super-admin email; the legacy
    // admin_email allow-list key is left unset. The recipient must still be
    // resolved through VisitorStatus::superAdminEmail(), so the alert is not
    // silently dropped.
    config()->set('larafoundry.auth.failed_login.admin_email', null);
    config()->set('larafoundry.security.super_admin.email', 'super@x.test');
    Notification::fake();

    event(new Failed('web', null, ['email' => 'super@x.test', 'password' => 'nope']));

    Notification::assertSentTo(
        new AnonymousNotifiable,
        AdminLoginAttemptNotification::class,
        fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'super@x.test' && $n->step === 'password',
    );
});

it('sends no mail when notify_admin is off', function () {
    config()->set('larafoundry.auth.failed_login.notify_admin', false);
    Notification::fake();

    event(new Failed('web', null, ['email' => 'admin@x.test', 'password' => 'nope']));

    Notification::assertNothingSent();
});

it('respects alert_on: with only admin_otp, OTP mails but password and PIN do not', function () {
    config()->set('larafoundry.auth.failed_login.alert_on', ['admin_otp']);
    Notification::fake();
    $admin = alertAdmin();

    // Password failure: filtered out.
    event(new Failed('web', null, ['email' => 'admin@x.test', 'password' => 'nope']));
    Notification::assertNothingSent();

    // OTP failure: allowed.
    app(AlertOnAdminOtpFailure::class)->handle(new TwoFactorAuthenticationFailed($admin));
    Notification::assertSentTo(new AnonymousNotifiable, AdminLoginAttemptNotification::class);
});

it('respects channels: removing mail silences the core mail even when enabled', function () {
    config()->set('larafoundry.auth.failed_login.channels', []);
    Notification::fake();

    event(new Failed('web', null, ['email' => 'admin@x.test', 'password' => 'nope']));

    Notification::assertNothingSent();
});

it('lets a host swap the mail notification class via config', function () {
    config()->set('larafoundry.auth.failed_login.notification', HostAdminAlert::class);
    Notification::fake();

    event(new Failed('web', null, ['email' => 'admin@x.test', 'password' => 'nope']));

    Notification::assertSentTo(new AnonymousNotifiable, HostAdminAlert::class);
});

/** A host override subclass used to prove the override-seam. */
class HostAdminAlert extends AdminLoginAttemptNotification {}
