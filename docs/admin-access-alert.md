# Admin-access security alert

The admin-access alert is the core's neutral security signal for a failed attempt
to enter the platform super-admin account. Whenever an authentication step that
guards the operator identity fails (a bad password, a throttle lockout, a wrong
operator-console OTP, or a wrong session PIN) and the target is the super-admin,
the core raises one unified event. The core's own mail channel listens to that
event and emails the operator by default; a host plugs in any additional channel
(Telegram, Slack, ...) by listening to the same event, with zero changes to the
core.

This page is the accurate reference for the shipped package. The event, the
policy and the mail channel live under `Dmitryisaenko\LaraFoundry\Auth\`.

## Contents

- [Why it exists](#why-it-exists)
- [The event](#the-event)
- [Configuration](#configuration)
- [The default mail channel](#the-default-mail-channel)
- [Adding a host channel (Telegram example)](#adding-a-host-channel-telegram-example)
- [Security notes](#security-notes)
- [Testing](#testing)

## Why it exists

A platform operator's account is the single most valuable identity on the system.
Most SaaS apps notice an attack on a tenant user but stay blind to repeated
failed sign-ins against the operator console itself. The core closes that gap with
one signal that fires on every failure type that protects the super-admin, routed
through a single config gate so an operator can tune what they want to hear about
and where, without touching code.

The design has two properties worth naming:

1. **One event, many sources, one gate.** Password, lockout, OTP and PIN failures
   all converge on a single event and a single policy. The "which failure type
   reaches which channel" matrix lives in exactly one place and never drifts
   between channels.
2. **Channels are pluggable, the core stays neutral.** The core ships only the
   mail channel. Anything host-specific (a chat bot, an SMS gateway) is a host
   listener on the same event. The core never grows a dependency on a delivery
   provider.

## The event

`Dmitryisaenko\LaraFoundry\Auth\Events\AdminAccessAttemptFailed` is the public
signal. It is a plain, readonly value object:

| Property | Type | What it carries |
|----------|------|-----------------|
| `step` | `'password'` \| `'lockout'` \| `'admin_otp'` \| `'pin'` | The auth step that failed. |
| `ip` | `string` | The client IP, best-effort. |
| `userAgent` | `string` | The raw `User-Agent` header, best-effort. |
| `device` | `DeviceFingerprint` \| `null` | A dependency-free, coarse device summary (browser / OS / name), resolved offline. `null` when it cannot be resolved. |
| `email` | `string` \| `null` | The targeted admin email when known (the `password` and `lockout` steps), `null` for `admin_otp` and `pin`. |

The event is raised for **every** targeted-admin failure (a raw signal, useful as
a tripwire even with notifications off). Whether any listener reacts is decided by
config, not by the dispatcher.

### When it is dispatched (the three sources)

| Source | Listens to | Raises | Gating |
|--------|-----------|--------|--------|
| `LogFailedLoginAttempt` | Laravel's `Failed` and `Lockout` auth events (which Fortify's pipeline fires) | `password` / `lockout` | Only when the attempt targeted the super-admin email (`VisitorStatus::isSuperAdminEmail`). |
| `AlertOnAdminOtpFailure` | Fortify's `TwoFactorAuthenticationFailed` | `admin_otp` | Fortify fires this for **every** user's login-2FA miss, so the listener stays silent unless the user is the super-admin (`VisitorStatus::isAdmin`). |
| `PinController::check()` | A wrong session PIN entry | `pin` | The PIN-lock exists for every user, so the alert is raised only when the **super-admin's** own PIN entry fails. |

All three assemble the event the same way through `AdminAccessFailureContext`,
which collects the IP, User-Agent and device fingerprint from the current request
(no geo lookup, to keep the failure hot-path free of network latency).

## Configuration

Everything lives under `larafoundry.auth.failed_login` in `config/larafoundry.php`:

```php
'failed_login' => [
    // Master on/off switch for all admin-access alerts.
    'notify_admin' => env('LARAFOUNDRY_NOTIFY_LOGIN_FAIL', false),

    // The super-admin email an alert protects. Falls back to
    // security.super_admin.email via VisitorStatus when left null.
    'admin_email' => env('LARAFOUNDRY_ADMIN_EMAIL'),

    // Which failure TYPES raise an alert. For "OTP only" use ['admin_otp'].
    'alert_on' => ['password', 'lockout', 'admin_otp', 'pin'],

    // Which CHANNELS deliver. The core knows 'mail'; a host listening on
    // AdminAccessAttemptFailed appends its own (e.g. 'telegram').
    'channels' => ['mail'],

    // Optional: a host may swap the core mail notification for its own
    // subclass of AdminLoginAttemptNotification (FQCN). Null = core default.
    'notification' => null,
],
```

### Three axes, one policy

`Dmitryisaenko\LaraFoundry\Auth\Support\AdminAccessAlertPolicy::shouldAlert($step, $channel)`
is the single source of truth every channel listener consults. It combines:

| Axis | Config key | Question it answers |
|------|-----------|---------------------|
| Master switch | `notify_admin` | Are alerts on at all? Off (the default) means nothing fires, even though the event is still raised. |
| Failure type | `alert_on` | Is this kind of failure one we care about? Default is all four; narrow it to e.g. `['admin_otp']` for "OTP only". |
| Delivery channel | `channels` | Is this channel opted in? The core knows `'mail'`; a host appends its own. |

Common tunings:

- **OTP failures only, by mail.** `'alert_on' => ['admin_otp']` (leave `channels`
  as `['mail']`).
- **Turn off email** (e.g. because you only want a chat channel). Remove `'mail'`
  from `channels`. The mail listener then no-ops.
- **Everything off.** Set `notify_admin` to `false` (the master switch), or its
  env `LARAFOUNDRY_NOTIFY_LOGIN_FAIL=false`.

### Recipient resolution

The mail channel sends to the super-admin address resolved through
`VisitorStatus::superAdminEmail()`: it reads the canonical
`security.super_admin.email` (env `LARAFOUNDRY_SUPER_ADMIN_EMAIL`) first, then
falls back to the legacy `auth.failed_login.admin_email` (env
`LARAFOUNDRY_ADMIN_EMAIL`). Setting only the super-admin email is enough; the mail
channel reads it through the same accessor the identity gate uses, so the alert is
never silently dropped.

### Relevant env variables

| Env | Maps to | Meaning |
|-----|---------|---------|
| `LARAFOUNDRY_NOTIFY_LOGIN_FAIL` | `auth.failed_login.notify_admin` | Master switch for alerts. |
| `LARAFOUNDRY_ADMIN_EMAIL` | `auth.failed_login.admin_email` | Legacy recipient, used only as a fallback. |
| `LARAFOUNDRY_SUPER_ADMIN_EMAIL` | `security.super_admin.email` | The canonical super-admin email, the preferred recipient. |

## The default mail channel

`Dmitryisaenko\LaraFoundry\Auth\Listeners\SendAdminAccessAlertMail` is the core's
neutral default channel. It is one listener among (potentially) many:

1. It asks `AdminAccessAlertPolicy::shouldAlert($event->step, 'mail')` and returns
   early if the master switch, the type filter or the channel allow-list say no.
2. It resolves the recipient through `VisitorStatus::superAdminEmail()` and returns
   early if no super-admin email is configured.
3. It sends `AdminLoginAttemptNotification` (mail-only) to that address via an
   on-demand `Notification::route('mail', ...)`.

### The notification and localization

`Dmitryisaenko\LaraFoundry\Auth\Notifications\AdminLoginAttemptNotification` is
mail-only (`via()` returns `['mail']`) and fully localized through the
`larafoundry::auth.admin_alert.*` translation keys (subject, intro, IP, agent,
optional device line, outro). The failed step is rendered through a per-step key
`larafoundry::auth.admin_alert.step.{step}` so each failure type reads naturally
(for example "operator-console two-factor" or "session PIN"), falling back to the
raw step key if a translation is missing. The device line only appears when a
device fingerprint was resolved.

### Overriding the notification

A host that only wants to reword or restructure the mail can point
`larafoundry.auth.failed_login.notification` at its own subclass of
`AdminLoginAttemptNotification` (FQCN). The mail listener validates that the
configured class is a subclass of the core notification and falls back to the core
default otherwise, so a misconfiguration never breaks mail.

## Adding a host channel (Telegram example)

Delivering the same alert over a chat channel is a host concern: you add a
listener on `AdminAccessAttemptFailed`, gate it through the same policy, and opt
the channel in via config. The core needs **zero** changes.

This example uses the community package
[`laravel-notification-channels/telegram`](https://github.com/laravel-notification-channels/telegram),
which the **host** installs. The core neither requires nor suggests it.

**1. Install the channel package in your host.**

```bash
composer require laravel-notification-channels/telegram
```

Set the bot token (the package reads `services.telegram-bot-api.token`):

```php
// config/services.php
'telegram-bot-api' => [
    'token' => env('TELEGRAM_BOT_TOKEN'),
],
```

**2. Opt the channel in.** Add `'telegram'` to the channel allow-list so the
policy lets it fire:

```php
// config/larafoundry.php
'auth' => [
    'failed_login' => [
        'channels' => ['mail', 'telegram'],
        // ...
    ],
],
```

**3. Write the listener.** Gate it through `AdminAccessAlertPolicy` with your own
channel name, so it respects the master switch and the `alert_on` type filter
exactly like the mail channel does:

```php
namespace App\Listeners;

use Dmitryisaenko\LaraFoundry\Auth\Events\AdminAccessAttemptFailed;
use Dmitryisaenko\LaraFoundry\Auth\Support\AdminAccessAlertPolicy;
use NotificationChannels\Telegram\TelegramMessage;
use Illuminate\Support\Facades\Notification;

class SendAdminAccessAlertTelegram
{
    public function handle(AdminAccessAttemptFailed $event): void
    {
        if (! AdminAccessAlertPolicy::shouldAlert($event->step, 'telegram')) {
            return;
        }

        $chatId = config('services.telegram-bot-api.chat_id');

        Notification::route('telegram', $chatId)->notify(
            new class($event) extends \Illuminate\Notifications\Notification {
                public function __construct(public AdminAccessAttemptFailed $event) {}

                public function via(object $notifiable): array
                {
                    return ['telegram'];
                }

                public function toTelegram(object $notifiable): TelegramMessage
                {
                    return TelegramMessage::create()
                        ->content(sprintf(
                            "Admin access failed: %s\nIP: %s",
                            $this->event->step,
                            $this->event->ip,
                        ));
                }
            }
        );
    }
}
```

**4. Register the listener** in your host (for example in `AppServiceProvider::boot()`):

```php
use Illuminate\Support\Facades\Event;
use Dmitryisaenko\LaraFoundry\Auth\Events\AdminAccessAttemptFailed;
use App\Listeners\SendAdminAccessAlertTelegram;

Event::listen(AdminAccessAttemptFailed::class, SendAdminAccessAlertTelegram::class);
```

That is the whole recipe. The mail and Telegram channels now react to the same
event under the same `notify_admin` / `alert_on` gate, and the "which failure type
reaches which channel" decision still lives in one place. The chat token and the
chat id are host config; the core stays neutral.

## Security notes

- **The raw event is always raised** for a targeted-admin failure, even with
  notifications off, so it is available as an audit tripwire. Delivery, not
  detection, is what the config gates.
- **OTP and PIN sources gate strictly on the super-admin identity.** Fortify's
  2FA-failed event and the session PIN both exist for every user; the listeners
  raise the admin alert only when the failing identity is the operator, so a
  normal user's 2FA or PIN miss never alerts the operator.
- **The recipient is resolved server-side** from the super-admin email config,
  never from request input, so the alert can never be redirected by an attacker.
- **The notification override is type-checked.** A configured `notification` class
  that is not a subclass of the core notification is ignored in favour of the
  core default, so a bad config cannot break mail or smuggle in an arbitrary class.

## Testing

The behaviour is covered by Pest: `tests/Feature/Auth/AdminAccessAlertTest.php`
(the event, the policy axes, the mail channel, the per-source gating) plus an
updated `LogFailedLoginAttemptTest`. The full core suite is green.
