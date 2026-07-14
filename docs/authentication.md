# Authentication and Sessions

The auth layer builds on Laravel Fortify for the login, register, password-reset,
email-verification and TOTP two-factor flows, and adds the pieces Fortify does not
cover: social sign-in via Socialite, per-device session tracking, a cross-device
QR login, a session PIN-lock, a super-admin OTP entry gate, a blocked/deleted
account gate and localized auth mail. A host keeps its own `User` model and
composes the behaviour on with one trait; the routes, middleware and Inertia
pages ship inside the package.

This is the current, accurate reference for the shipped package. An older planning
draft lives at [modules/authentication.md](modules/authentication.md); it predates
the build and uses names that changed (see [What changed](#what-changed-from-the-early-draft)).

## Contents

- [Install](#install)
- [Configuration](#configuration)
- [Usage](#usage)
- [API reference](#api-reference)
- [Security notes](#security-notes)
- [Testing](#testing)
- [What changed from the early draft](#what-changed-from-the-early-draft)

## Install

Auth ships with the core package; there is nothing extra to require. The host
opts in by:

1. Adding the `IsLaraFoundryUser` trait to its `User` model, and merging the
   trait's fillable, casts and hidden contributions into the model's own.
2. Publishing the Inertia auth pages (`vendor:publish --tag=larafoundry-pages`)
   so Fortify has views to render.
3. Pointing every Fortify view at those pages by calling
   `LaraFoundryAuth::registerFortifyViews()` from the host's
   `FortifyServiceProvider::boot()` (pairs with `'views' => false` in the host's
   `config/fortify.php`, that is Fortify SPA mode).
4. Applying the core auth middleware aliases to its authenticated web group (see
   [Middleware aliases](#middleware-aliases)).

```php
// app/Models/User.php
use Dmitryisaenko\LaraFoundry\Auth\Concerns\IsLaraFoundryUser;
use Dmitryisaenko\LaraFoundry\Contracts\HasLocalePreference;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements MustVerifyEmail, HasLocalePreference
{
    use IsLaraFoundryUser;

    protected $fillable = ['name', 'email', 'password'];

    protected function casts(): array
    {
        return array_merge($this->laraFoundryCasts(), [
            // host-specific casts here
        ]);
    }
}
```

The trait pulls in Sanctum's `HasApiTokens`, `Notifiable` and Fortify's
`TwoFactorAuthenticatable`, and exposes `laraFoundryFillable()`,
`laraFoundryCasts()` and `laraFoundryHidden()` for the host to merge. The
package's migrations (which add the identity, session-tracking and QR tables and
columns) are loaded automatically.

```php
// app/Providers/FortifyServiceProvider.php
use Dmitryisaenko\LaraFoundry\Auth\LaraFoundryAuth;

public function boot(): void
{
    LaraFoundryAuth::registerFortifyViews();
}
```

## Configuration

The auth-related settings live under three keys of `config/larafoundry.php`:
`auth` (Fortify add-ons, OAuth, QR, admin-alerting), `pin` (the session PIN-lock),
and `security.super_admin` (the operator OTP gate and blocked-account redirect).

```php
'auth' => [
    'password_min_length' => 8,
    'presentation' => env('LARAFOUNDRY_AUTH_PRESENTATION', 'page'), // page | modal

    'qr' => [
        'enabled' => env('LARAFOUNDRY_QR_ENABLED', true),
        'ttl_minutes' => 5,
        'absolute_ttl_minutes' => 15,
        'size' => 400,
        'poll_interval_ms' => 2000,
    ],

    'oauth' => [
        'enabled' => env('LARAFOUNDRY_OAUTH_ENABLED', false),
        'providers' => ['google', 'facebook', 'twitter'],
        'link_existing' => false,
        'redirect_after_login' => '/',
        'community_drivers' => [
            'apple' => '\SocialiteProviders\Apple\AppleExtendSocialite',
            'microsoft' => '\SocialiteProviders\Microsoft\MicrosoftExtendSocialite',
        ],
    ],

    'failed_login' => [
        'notify_admin' => env('LARAFOUNDRY_NOTIFY_LOGIN_FAIL', false),
        'admin_email' => env('LARAFOUNDRY_ADMIN_EMAIL'),
        'alert_on' => ['password', 'lockout', 'admin_otp', 'pin'],
        'channels' => ['mail'],
        'notification' => null,
    ],

    'two_factor' => ['confirm' => true],
    'blocked_redirect_route' => null,
],

'pin' => [
    'enabled' => env('LARAFOUNDRY_PIN_ENABLED', true),
    'length' => 4,
    'idle_timeout' => 1800,
    'max_attempts' => 5,
    'lockout_minutes' => 15,
],

'security' => [
    'super_admin' => [
        'email' => env('LARAFOUNDRY_SUPER_ADMIN_EMAIL'),
        'require_otp' => env('LARAFOUNDRY_ADMIN_REQUIRE_OTP', true),
        'two_factor_setup_route' => env('LARAFOUNDRY_ADMIN_2FA_SETUP_ROUTE'),
        // console_route, allowed_routes also live here (operator-console phase)
    ],
],
```

| Key | Default | What it does |
|-----|---------|--------------|
| `auth.password_min_length` | `8` | Floor for new and reset passwords. The core also applies Laravel's `Password::defaults()` rules on top. |
| `auth.presentation` | `page` | How the guest auth screens are surfaced: `page` (a full page) or `modal` (an overlay). Read by the frontend via the shared `auth_presentation` prop; an unknown value falls back to `page`. |
| `auth.qr.enabled` | `true` | Master switch for QR cross-device login. When off, neither the web-side routes nor the `auth:sanctum` verify endpoint are registered at all. |
| `auth.qr.ttl_minutes` | `5` | How long a freshly generated code stays valid; the code slides forward on re-issue. |
| `auth.qr.absolute_ttl_minutes` | `15` | Hard cap measured from creation, so a code that keeps sliding forward still dies. |
| `auth.qr.size` | `400` | Rendered QR side length in px. |
| `auth.qr.poll_interval_ms` | `2000` | How often the web side polls for approval (surfaced to the frontend). |
| `auth.oauth.enabled` | `false` | Master switch for the Socialite redirect/callback routes and the login buttons. |
| `auth.oauth.providers` | `['google','facebook','twitter']` | The allow-list of accepted provider slugs. A slug only works once the host supplies that provider's credentials in `config/services.php`; the core ships none. |
| `auth.oauth.link_existing` | `false` | When an OAuth identity resolves to an email that already has a LOCAL account, whether to auto-link. Default `false` closes an account-takeover vector; the callback then refuses and tells the user to sign in locally first. |
| `auth.oauth.redirect_after_login` | `/` | Where the OAuth callback lands after a successful login (via `redirect()->intended()`). |
| `auth.oauth.community_drivers` | apple, microsoft | Map of `slug => handler FQCN` for `socialiteproviders/*` packages. For any slug here that is ALSO in `providers` AND whose handler class is installed, the core auto-registers the `SocialiteWasCalled` listener, so the host writes no listener. Values are strings, never `::class`, so a missing package never trips the autoloader. |
| `auth.failed_login.notify_admin` | `false` | Master on/off for all admin-access alerts. |
| `auth.failed_login.admin_email` | null | The super-admin email an alert protects. Falls back to `security.super_admin.email`. |
| `auth.failed_login.alert_on` | `['password','lockout','admin_otp','pin']` | Which failure types raise an alert. For "OTP only" use `['admin_otp']`. |
| `auth.failed_login.channels` | `['mail']` | Which channels deliver. The core knows `mail`; a host appends its own (for example `telegram`) by listening on `AdminAccessAttemptFailed`. |
| `auth.failed_login.notification` | null | Optional FQCN of a host subclass of `AdminLoginAttemptNotification`. Null uses the core default. |
| `auth.two_factor.confirm` | `true` | Require the user to confirm TOTP enrolment with a live code before it is active. |
| `auth.blocked_redirect_route` | null | Route name `EnsureAccountIsActive` sends a blocked/deleted user to. Null falls back to `/` with a flashed error. |
| `pin.enabled` | `true` | Master switch. When off, `CheckPinLock` is a no-op even if a user has a PIN set. |
| `pin.length` | `4` | PIN length in digits. Enforced on set (`digits:length`) and read by the entry screen. |
| `pin.idle_timeout` | `1800` | Seconds of inactivity after which the session auto-locks (only the idle session). |
| `pin.max_attempts` | `5` | Wrong entries in a row before PIN input is temporarily locked. |
| `pin.lockout_minutes` | `15` | How many minutes PIN input is locked after exceeding `max_attempts`. |
| `security.super_admin.email` | null | The operator's exclusive email. When set, only it (with the `is_admin` flag) gets admin status, and the email is reserved (cannot register or be claimed via OAuth). |
| `security.super_admin.require_otp` | `true` | Master switch for the operator OTP step-up gate (`EnsureAdminOtpVerified`). |
| `security.super_admin.two_factor_setup_route` | null | Route name of the host's 2FA-enrolment screen. A super-admin without confirmed 2FA is sent here; null means the gate denies with 403 (fail closed). |

## Usage

### The user trait

`IsLaraFoundryUser` carries the identity slice of phase 1.1 only (identity,
locale, OAuth linkage, blocking state, PIN and session tracking). It deliberately
does not pull in tenancy or RBAC behaviour, which arrive as their own traits in
later phases. Beyond the fillable/casts/hidden merge helpers it exposes:

```php
$user->isAdmin();           // raw is_admin flag (full decision is VisitorStatus)
$user->isBlocked();         // user_blocked_at is set
$user->isDeleted();         // user_deleted_at is set (legacy soft-delete column)
$user->isPurged();          // user_purged_at is set (irreversible erasure)
$user->isOauthOnly();       // no local password, provider_name present
$user->hasPin();            // a session PIN is set
$user->checkPinCode($pin);  // timing-safe Hash::check against the stored hash
$user->preferredLocale();   // ?string, satisfies HasLocalePreference
$user->sessions();          // HasMany UserSession (the tracked devices)
$user->avatar_url;          // always-resolvable URL (stored path, OAuth URL, or initials)
```

### Social (OAuth) sign-in

Enable `auth.oauth.enabled`, list the slugs in `auth.oauth.providers`, and supply
each provider's credentials in `config/services.php`. The core exposes two guest
routes and the frontend renders one button per listed provider (config-driven,
shared as the `auth_oauth` Inertia prop):

```
GET  auth/oauth/{provider}            -> larafoundry.oauth.redirect
GET  auth/oauth/{provider}/callback   -> larafoundry.oauth.callback
```

`OAuthController` resolves a callback strictly: an existing provider link always
wins; an email that already has a local account is linked only when
`link_existing` is true (otherwise refused); a fresh email creates a new,
provider-verified account. For a community `socialiteproviders/*` provider, add
its package, list the slug in `providers`, and map its handler under
`community_drivers`; the core registers the driver for you.

### Session PIN-lock

Any user may set a short PIN from their profile for quick re-entry (a
Telegram-style screen lock) instead of a full re-login after idling. The routes
are under `pin.*`:

```
GET   pin            -> pin.enter    (the unlock screen)
POST  pin/check      -> pin.check    (verify + unlock this device; throttle:10,1)
POST  pin/enable     -> pin.enable   (set or replace the PIN)
POST  pin/disable    -> pin.disable  (remove the PIN, current PIN required)
POST  pin/lock       -> pin.lock     (lock every session of the user)
```

`CheckPinLock` (alias `larafoundry.pin`) auto-locks a session past
`pin.idle_timeout` and bounces every request to `pin.enter` until it is entered;
it always allows `pin.*`, `logout` and `password.confirm` so the user can reach
the unlock screen. Lock state lives per-session on the `user_sessions` row, so a
background request cannot bypass it and each device locks independently.

### QR cross-device login

With `auth.qr.enabled`, the login screen can show a QR that an already-signed-in
device scans to approve a new login (the WhatsApp-Web pattern). The web (guest)
side generates and polls; the authenticated device approves through a
Sanctum-guarded API endpoint:

```
POST  larafoundry/qr/generate                 -> larafoundry.qr.generate  (guest, throttle:10,1)
POST  larafoundry/qr/poll                      -> larafoundry.qr.poll      (guest, throttle:40,1)
GET   larafoundry/qr/verify/{id}/{token}       -> larafoundry.qr.verify    (auth:sanctum, throttle:10,1)
```

The verify endpoint is guard-agnostic by design: with Sanctum's stateful-domain
config a same-domain web request authenticates by session cookie today, and a
future native app authenticates with a Bearer token, same controller. The host
supplies the `/api` prefix and stateful middleware in `bootstrap/app.php` (the
`withRouting(api:)` / `statefulApi()` wiring done in the integration step).

### Session tracking

Every authenticated device gets a `user_sessions` row with a device fingerprint
and last-activity, so a host can render "active sessions" and offer eviction. This
is captured by `TrackSessionActivity` (alias `larafoundry.session.track`), a
per-request middleware, not a `Login` listener, because the login pipeline
regenerates the session id several times and only a per-request pass sees the
final id. `SessionController` lets the user revoke devices:

```
DELETE  auth/sessions/others       -> larafoundry.sessions.destroy-others
DELETE  auth/sessions/{session}    -> larafoundry.sessions.destroy
```

Genuine remote eviction requires the `database` session driver; on the
file/cookie driver the framework session lives outside the package's reach.

### Blocked and deleted accounts

Apply `EnsureAccountIsActive` (alias `larafoundry.account.active`) to the
authenticated web group. On every request it resolves the visitor status and, for
a blocked or deleted account, logs the user out, invalidates the session and
redirects to `auth.blocked_redirect_route` (JSON/XHR clients get a 403).

## API reference

### `IsLaraFoundryUser` (trait on the `User` model)

Merge helpers `laraFoundryFillable()`, `laraFoundryCasts()`, `laraFoundryHidden()`;
predicates `isAdmin()`, `isBlocked()`, `isDeleted()`, `isPurged()`,
`isOauthOnly()`, `hasPin()`, `checkPinCode()`; locale `preferredLocale()` /
`setPreferredLocale()`; the `sessions()` and `socialLinks()` relations; the
`avatar_url` accessor; and `recordSessionActivity()` (called by the tracking
middleware). Uses `HasApiTokens`, `Notifiable`, `TwoFactorAuthenticatable`.

### `LaraFoundryAuth` (host wiring helper)

| Method | Purpose |
|--------|---------|
| `registerFortifyViews()` | Point every Fortify auth view (login, register, forgot/reset password, verify email, confirm password, two-factor challenge) at the core's published Inertia pages under `Auth/`. Call from `FortifyServiceProvider::boot()`. |

### `VisitorStatus` (identity status resolver)

| Member | Purpose |
|--------|---------|
| `for(?Authenticatable)` | Returns one of the constants `GUEST`, `BLOCKED`, `DELETED`, `ADMIN`, `VERIFIED`, `AUTHENTICATED` (in that precedence). |
| `isAdmin(?Authenticatable)` | The `is_admin` flag AND (when a super-admin email is configured) an email match, defence-in-depth over a raw flag. |
| `superAdminEmail()` (static) | The configured super-admin email, falling back to `auth.failed_login.admin_email`, else null. |
| `isSuperAdminEmail(?string)` (static) | Whether an email is the reserved super-admin email (case-insensitive). Used by the registration, OAuth and company-ownership reservation guards. |

### Fortify actions (rebound in the service provider)

| Action | Contract | Notes |
|--------|----------|-------|
| `CreateNewUser` | `CreatesNewUsers` | Registration. Password strength from `password_min_length` + `Password::defaults()`; reserves the super-admin email; records terms acceptance when a Terms page is published. |
| `ResetUserPassword` | `ResetsUserPasswords` | Password reset with the same strength rules. |
| `UpdateUserPassword` | `UpdatesUserPasswords` | In-account password change. |
| `UpdateUserProfileInformation` | `UpdatesUserProfileInformation` | Profile update. |

### Controllers

| Controller | Serves |
|------------|--------|
| `OAuthController` | `redirect()`, `callback()` for social sign-in. |
| `QrLoginController` | `generate()`, `poll()` (web/guest) and `verify()` (auth:sanctum). |
| `PinController` | `enter()`, `check()`, `enable()`, `disable()`, `lock()`. |
| `SessionController` | `destroyOthers()`, `destroy()` for self-service device revocation. |

### Models

| Model | Notes |
|-------|-------|
| `UserSession` | One tracked device per row (`session_id`, `login_method`, device fingerprint, `last_activity`, `last_route_name`, `active_company_id`, PIN-lock columns). Resolves the host's configured user model. |
| `SignInRequest` | A QR sign-in request. `token` stores the SHA-256 hash of the QR secret (plaintext is never persisted), with `expires_at`, `approved`, `approved_at` and approver fingerprint. |

### Middleware aliases

| Alias | Class | Purpose |
|-------|-------|---------|
| `larafoundry.account.active` | `EnsureAccountIsActive` | Logs out and redirects a blocked/deleted account. |
| `larafoundry.session.track` | `TrackSessionActivity` | Creates/refreshes the tracked session row each request. |
| `larafoundry.pin` | `CheckPinLock` | Auto-locks an idle session and gates it to the unlock screen. |
| `larafoundry.confine_admin` | `RedirectSuperAdminToConsole` | Keeps the super-admin inside the operator console. |
| `larafoundry.admin.otp` | `EnsureAdminOtpVerified` | The operator OTP step-up gate (registered by the activity-log boot). |

### Events and listeners

| Event | Listener | Fired when |
|-------|----------|-----------|
| `AdminAccessAttemptFailed` | `SendAdminAccessAlertMail` | A super-admin auth step fails (password/lockout/OTP/PIN funnel into this one event). |
| `TwoFactorAuthenticationFailed` (Fortify) | `AlertOnAdminOtpFailure` | A wrong operator-console OTP, re-raised as `AdminAccessAttemptFailed`. |
| `Failed` / `Lockout` (Illuminate) | `LogFailedLoginAttempt` | A failed password login or a rate-limit lockout. |
| `Verified` (Illuminate) | `SendWelcomeNotification` | The user verifies their email. |
| `Login` (Illuminate) | `SyncCookieConsentOnLogin` | Adopt a guest's cookie-consent choice on first login. |

The core's neutral default alert channel is mail. A host adds Telegram, Slack and
so on by listening to `AdminAccessAttemptFailed` and appending its channel name to
`auth.failed_login.channels`, no core change needed. `AdminAccessAlertPolicy`
centralises the "failure type x channel" decision.

### Localized auth mail

The service provider routes Fortify's verify-email and password-reset mails
through the editable email templates (`email_verification` / `password_reset`),
falling back to a `MailMessage` built from the translated `larafoundry::auth`
strings when a template is switched off. Fortify still owns sending and link
generation.

## Security notes

The auth layer hardens the extracted donor code. The guarantees worth knowing:

- **No account-takeover on OAuth.** The callback never binds an OAuth login to a
  pre-existing local account on email alone. Linking is off by default
  (`link_existing => false`); with it on, an email match links, otherwise the
  attempt is refused. An already-linked provider identity is the only
  unconditional match.
- **The super-admin email is reserved.** It cannot be claimed through public
  registration or fresh OAuth linking; both guards call
  `VisitorStatus::isSuperAdminEmail()` (case-insensitive). OAuth refuses the
  reserved email generically, so the refusal does not reveal it is the admin's.
- **Privilege columns are not mass-assignable.** `is_admin`, `user_blocked_at`,
  `user_deleted_at` and `block_code` are excluded from the trait's fillable, so
  they can never be flipped through request input. Secrets (`password`,
  `pin_code`, provider tokens, `two_factor_secret` and recovery codes) are hidden
  from serialization.
- **Admin status is defence-in-depth.** A flipped `is_admin` flag alone does not
  grant admin: when a super-admin email is configured, `VisitorStatus::isAdmin()`
  also requires the email to match.
- **The operator console is OTP-gated per session.** `EnsureAdminOtpVerified`
  requires confirmed 2FA (or 403/redirect to enrolment) and a fresh TOTP once per
  session. The OTP flag is dropped on every login and logout, so an OAuth login
  (which skips Fortify's login-time 2FA) still has to clear the gate, and a stolen
  session cookie is caught too.
- **QR login is hardened over the donor.** The token is SHA-256 hashed in the DB
  (plaintext only ever in the QR URL); an absolute TTL cap stops a code sliding
  forward forever; the session is regenerated before login (no fixation); a
  bad/expired/foreign code is a clean 4xx because nothing is decrypted; the
  super-admin and blocked/deleted accounts cannot approve a web sign-in; every
  attempt is audited to the activity log; and both web and verify surfaces are
  throttled.
- **PIN entry is rate-limited.** A per-session attempt counter trips a lockout
  window (`pin.max_attempts` / `pin.lockout_minutes`) on top of a per-IP route
  throttle, closing the unlimited brute-force the donor's 4-digit PIN allowed.
  Replacing an existing PIN requires the current session to be unlocked, so a
  locked session cannot set a fresh PIN to escape the lock. Lock state is
  DB-persisted per session, so a background request cannot bypass it.
- **Device revocation closes the IDOR.** `auth/sessions/{session}` is bound by id
  and could resolve any user's row, so the controller checks ownership and answers
  404 on a mismatch (indistinguishable from a missing row). The current session is
  not revocable there (that is logout), so a probe cannot self-evict.
- **Blocked/deleted accounts are ejected on every request.** `EnsureAccountIsActive`
  logs the user out, invalidates the session and regenerates the CSRF token before
  redirecting, so a mid-session block takes effect at once.

## Testing

The auth suite lives in `tests/Feature/Auth/` and runs on the package's testbench
harness. Notable files:

- `ActionsTest`: the Fortify action rebinds (password strength, super-admin
  reservation, terms recording).
- `OAuthControllerTest`: the redirect/callback flow and the account-takeover
  resolution branches.
- `OAuthCommunityDriversTest`: the `socialiteproviders/*` auto-registration guard.
- `QrLoginTest`: generate/poll/verify, hashing, TTL cap and the approval guards.
- `SanctumSeamTest`: the `auth:sanctum` verify endpoint's guard-agnostic behaviour.
- `PinLockTest`: idle auto-lock, unlock, attempt lockout and the allow-list.
- `TrackSessionActivityTest`: per-request row creation/refresh and the PIN-route skip.
- `SessionControllerTest` and `SessionRevokeTest`: "log out others"/single revoke
  and the ownership IDOR check.
- `EnsureAccountIsActiveTest`: the blocked/deleted ejection.
- `AdminAccessAlertTest` and `LogFailedLoginAttemptTest`: the unified admin-alert
  funnel and failed-login logging.
- `AuthMailTemplatesTest` and `WelcomeMailTest`: the localized/templated auth mail
  and the welcome-on-verify notification.
- `MigrationsTest`: the identity, session and QR schema.

Run them with Pest:

```bash
composer test
```

## What changed from the early draft

If you arrived from the older [modules/authentication.md](modules/authentication.md),
these names and scope points changed on the way to the shipped package:

| Early draft | Shipped |
|-------------|---------|
| Hand-rolled `AuthenticatedSessionController` / `RegisteredUserController` / `LoginRequest` | Login and registration are served by Laravel Fortify; the core rebinds Fortify's action contracts (`CreateNewUser`, etc.) instead of owning the controllers. |
| `UserSession` model with a `Require2FA` admin controller | `UserSession` remains; admin 2FA is Fortify's per-user TOTP plus the `EnsureAdminOtpVerified` step-up gate, not a bespoke `Require2FA`. |
| `LoginWithQrCode` controller, `SignInRequest` with encrypted token, `Crypt::encrypt()` id | `QrLoginController`; the token is SHA-256 hashed (plaintext only in the QR), no `Crypt` of id/token, an absolute TTL cap, and the verify step moved behind `auth:sanctum`. |
| `CheckPinLockMiddleware` / `PinController`, config in `config/security.php` | `CheckPinLock` / `PinController`; config lives under the `pin` key of `config/larafoundry.php` with an added attempt-counter lockout. |
| `GetVisitorStatusAction` with 6 states incl. `forcelogout` and `auth`/`authBlocked` | `VisitorStatus` resolver with `GUEST`/`BLOCKED`/`DELETED`/`ADMIN`/`VERIFIED`/`AUTHENTICATED`; the company-aware states (force-logout by admin IP, "pick a company") move to later phases. |
| `AdminAccess` / `prevent.nonadmin.routes` / IP-whitelist + Telegram named directly | Middleware aliases `larafoundry.account.active`, `larafoundry.session.track`, `larafoundry.pin`, `larafoundry.confine_admin`; alerting is one `AdminAccessAttemptFailed` event with a mail default, host adds Telegram by listening. |
| `jenssegers/agent` for device detection | A dependency-free `UserAgentDeviceResolver` (bound to `DeviceFingerprintResolver`); a host may rebind a richer parser. |
| Config via `.env` keys like `ADMIN_IPS`, `ADMIN_2FA_SECRET`, `TELEGRAM_*` | Config keys under `auth`, `pin` and `security.super_admin` in `config/larafoundry.php`. |
