# Activity Log

The activity log records who did what, from where, on every significant action.
Each entry captures the actor (causer), the object acted on (subject), the device
and IP behind the request, the route and HTTP method, and - off the request path -
an async geolocation of the IP. It is a platform-operator tool: a super-admin reads
it globally, there is no tenant scoping. The core logs its own auth, tenancy, RBAC,
media, tickets and notification events out of the box; a host adds its domain events
the same way, through a config registry.

This is the current, accurate reference for the shipped package. An older planning
draft lives at [modules/logging.md](modules/logging.md); it predates the build and
uses names that changed (see [What changed](#what-changed-from-the-early-draft)).

## Contents

- [Install](#install)
- [Configuration](#configuration)
- [Usage](#usage)
- [API reference](#api-reference)
- [Security notes](#security-notes)
- [Testing](#testing)
- [What changed from the early draft](#what-changed-from-the-early-draft)

## Install

The activity log ships with the core package on top of
`spatie/laravel-activitylog`; there is nothing extra to require. The service
provider registers everything: it binds the core's `Activity` model into
`activitylog.activity_model`, subscribes the event listener, loads the routes, and
registers the geo resolver and the opt-in route middleware.

Spatie's `activity_log` migration creates the base table; the core contributes a
migration that adds its context columns (`user_ip`, `user_agent`,
`user_device_type`, `user_device_name`, `user_os`, `user_browser`, `route_name`,
`request_method`, `full_url`, `response_code`, `is_successful`, `user_email`,
`geo_country`, `geo_city`, `geo_updated_at`). Both load automatically.

To customise the event registry, publish the config:

```bash
php artisan vendor:publish --tag=larafoundry-activitylog
```

That writes `config/larafoundry-activitylog.php`, where the host adds its own domain
events (see [Configuration](#configuration)).

## Configuration

Everything lives in `config/larafoundry-activitylog.php`. The keys:

```php
return [
    'enabled' => env('LARAFOUNDRY_ACTIVITY_LOG_ENABLED', true),

    'events' => [
        Login::class => ['group' => 'Auth', 'description' => 'User logged in', 'code' => 200],
        // ... core auth, tenancy, RBAC, media, tickets, notification events ...
    ],

    'geo' => [
        'enabled' => env('LARAFOUNDRY_ACTIVITY_GEO_ENABLED', true),
        'resolver' => IpApiGeoResolver::class,
        'cache_ttl' => 24 * 60 * 60,
        'timeout' => 5,
    ],

    'route_middleware' => [
        'enabled' => env('LARAFOUNDRY_ACTIVITY_ROUTE_LOG_ENABLED', false),
        'excluded' => ['sanctum.*', 'ignition.*', 'horizon.*', 'telescope.*'],
    ],

    'retention_days' => env('LARAFOUNDRY_ACTIVITY_RETENTION_DAYS', 365),

    'pii_redact_keys' => ['password', 'token', 'code', 'otp', '_token', /* ... */],
];
```

| Key | Default | What it does |
|-----|---------|--------------|
| `enabled` | `true` | Master switch. When false the event listener does not subscribe and the service does not write. Separate from spatie's own `activitylog.enabled`. |
| `events` | core registry | Event-class => `['group', 'description', 'code']`. Every listed event is logged automatically. The merge is additive: the host's published entries sit alongside the core's (event-class keys are unique). |
| `geo.enabled` | `true` | Opt-out geolocation. When false, geo columns stay null and no outbound lookup happens. |
| `geo.resolver` | `IpApiGeoResolver` | The `GeoResolver` implementation. Swap it to change provider. |
| `geo.cache_ttl` | `86400` | Seconds a resolved IP stays cached. |
| `geo.timeout` | `5` | Outbound HTTP timeout (seconds) for the lookup. |
| `route_middleware.enabled` | `false` | Turns on the per-request route logger. Noisy (one write per request); off by default. |
| `route_middleware.excluded` | `sanctum.*`, `ignition.*`, `horizon.*`, `telescope.*` | Route-name patterns (matched with `fnmatch`) the route middleware skips. |
| `retention_days` | `365` | Mapped onto spatie's `delete_records_older_than_days`, the age `activitylog:clean` prunes by. The command is NOT scheduled automatically; the host schedules it. |
| `pii_redact_keys` | password/token/code/otp/etc. | Query-string, route-parameter and property keys whose values are masked before storage. Case-insensitive. |

### The event registry follows the permissions-catalog pattern

The `events` registry works like `config/larafoundry-permissions.php`: the core ships
only events for its own modules, and domain events are host territory. Publish the
config and add your event classes:

```php
'events' => [
    // core entries stay ...
    App\Events\OrderShipped::class => [
        'group' => 'Orders', 'description' => 'Order shipped', 'code' => 200,
    ],
],
```

If an event object exposes `getLogProperties(): array`, its return value is merged
into the entry's properties under `event_properties`.

## Usage

### Automatic event logging

The bulk of logging is automatic: fire a registered event and an entry is written.
The core registers these groups out of the box:

- **Auth**: `Registered`, `Login`, `Logout`, `Failed` (login failed), `PasswordReset`,
  `ProfileUpdated`, `PasswordUpdated`, `AdminAccessAttemptFailed`.
- **Authorization**: `RoleCreated`, `RoleUpdated`, `RoleDeleted`.
- **Media**: `FileUploaded`.
- **Tenancy**: `CompanyCreated`, `CompanyInvitationSent`, `EmployeeRemoved`,
  `CompanyArchived`, `CompanyUnarchived`, plus the owner-employee lifecycle
  (`InvitationAccepted`, `InvitationRejected`, `InvitationWithdrawn`,
  `InvitationResent`, `EmployeeRemovalRequested`, `EmployeeRemovalCancelled`,
  `EmployeeRemovalRejected`, `EmployeeRoleChanged`).
- **Notifications**: `BroadcastNotificationSent`.
- **Tickets**: `TicketCreated`, `TicketReplied`.

The listener wraps each write in a try/catch: a transient DB problem degrades to a
logged warning, it never fails the action that triggered it.

### Manual logging

For one-off entries, use the `Activity` facade (or resolve `ActivityLogService`):

```php
use Dmitryisaenko\LaraFoundry\ActivityLog\Facades\Activity;

Activity::log(
    description: 'exported the customer list',
    logName: 'export',
    properties: ['count' => 1240],
    subject: $report,       // optional domain object acted on
    isSuccessful: true,
    responseCode: 200,
    geoSync: true,          // synchronous geo by default; pass false to enqueue
);
```

The caller is the causer. On a non-default guard (for example a Sanctum-token
endpoint where `Auth::user()` on the web guard is null) pass the resolved
`causer` explicitly. There is also `Activity::logMethodExecution($method, $result,
$before, $after)` for instrumenting a method with a before/after snapshot.

### Model auditing (opt-in trait)

Add the core's `LogsActivity` trait to a model to get automatic
created/updated/deleted entries with a real old -> new diff:

```php
use Dmitryisaenko\LaraFoundry\ActivityLog\Concerns\LogsActivity;

class Invoice extends Model
{
    use LogsActivity;
}
```

By default it logs all dirty attributes (`logAll()->logOnlyDirty()`), skips empty
diffs, and decorates the entry with the same device/IP/route context as event
logging. Narrow the scope by overriding `getActivitylogOptions()` (for example
`LogOptions::defaults()->logOnly(['status', 'total'])`).

### Route-access logging (opt-in middleware)

The `larafoundry.activity.route` middleware writes one entry per wrapped request.
It is off by default and noisy; enable `route_middleware.enabled`, exclude
high-frequency routes by name, then apply the alias to the route group you want
audited.

### The super-admin viewer

The package registers a two-view viewer under `admin/activity-log`, behind
`web, auth, verified, larafoundry.admin, larafoundry.admin.otp`:

- `admin.activity-log.index` - the whole-platform log, 100 per page, default a
  24-hour window.
- `admin.activity-log.user` (`users/{user}`) - one user's log (scoped by causer),
  50 per page, default a 1-hour window.

Both accept a `?hours` filter validated against the allow-list `1, 6, 12, 24, 48,
72` (anything else falls back to the default). Pages render through Inertia
(`Admin/Logs/GeneralLogs`, `Admin/Logs/UserLogs`) and are serialised by
`ActivityLogResource`.

## API reference

### `Activity` facade / `ActivityLogService`

| Method | Purpose |
|--------|---------|
| `log(string $description, string $logName = 'custom', array $properties = [], ?Model $subject = null, bool $isSuccessful = true, int $responseCode = 200, bool $geoSync = true, ?Authenticatable $causer = null): Model` | Write a one-off entry. Caller is the causer; geo is synchronous unless `geoSync: false`. |
| `logEvent(object $event, string $eventClassName, string $group, string $description, int $code, array $properties = []): void` | Log a registered event (used by the listener). Geo is always async. |
| `logMethodExecution(string $methodName, mixed $result, array $before = [], array $after = []): Model` | Log a method run with before/after state; a `Throwable` result marks it failed (code 500). |

### `Activity` model (`ActivityLog\Models\Activity`)

Extends spatie's `Activity`. Adds casts for the context columns and query scopes
`recent()`, `inLastHours(int $hours)`, `byCauser(int|string $causerId)`. The
`user()` relation and `user` accessor resolve the causer against the configured
auth model but return null unless the causer is actually a user (morph-safe), so a
non-user causer cannot surface a mis-joined row that merely shares the id.

### `EventLogRegistry`

Read-only accessor over `config('larafoundry-activitylog.events')`. Methods:
`all()`, `eventClasses()`, `metaFor(string $eventClass)`. The single read-point so
the listener and any UI see the same registry.

### `ActivityContext`

Collects the request-side context for an entry. `forRequest(?Request $request =
null): array` returns the device/IP/route columns (safe to call off an HTTP
request, falling back to nulls). `redactUrl()` and `redactProperties()` apply the
PII masking described below.

### `GeoResolver` contract

`resolve(string $ip): array{country, city}`. Default `IpApiGeoResolver` uses
ip-api.com, caches per IP, and answers private/loopback IPs locally without an
outbound call. Any failure degrades to "Unknown". `RetrieveActivityGeoData` is the
queued job that runs the resolver and stamps `geo_country` / `geo_city` /
`geo_updated_at` on the row (timeout 30s, 3 tries).

### `LogsActivity` trait

Wraps spatie's trait. `getActivitylogOptions()` declares what to log;
`tapActivity()` decorates the pending entry with request context. Opt in with `use
LogsActivity;` on a model.

### `ActivityLogPolicy`

`viewAny()` and `view()` both collapse to "is this a super-admin?" via the core's
`VisitorStatus`. Registered as the policy for the `Activity` model; mirrors the
`larafoundry.admin` route gate.

### Middleware alias

- `larafoundry.activity.route` (`LogActivity`): opt-in per-request route logging.

## Security notes

- **Causer and subject are distinct.** The causer is the actor; the subject is the
  object acted on. The service resolves them separately and never records the
  causer as its own subject. Auth events have no subject and correctly stay null.
  (The donor wrote the same user id into both.)
- **PII is redacted from the stored URL.** `full_url` has both query-string and
  route-path secrets masked to `[redacted]` before storage - a reset token or 2FA
  code in a query string, or a token in a path segment like
  `/qr/verify/{id}/{token}`, is never written verbatim. Masking works on the
  original query string directly (not `parse_str`/`http_build_query`) so dotted,
  spaced, repeated and array keys are not mangled, and relative URLs stay relative.
- **PII is redacted from properties.** Arbitrary `properties` are masked
  recursively by key against `pii_redact_keys` (case-insensitive) before storage.
- **Geolocation is opt-out and privacy-aware.** The lookup is an outbound call
  carrying the user's IP; set `geo.enabled` to false to disable it. Private,
  loopback and reserved IPs (IPv4 and IPv6, via PHP's native filter) are answered
  locally and never leave the server.
- **Audit failures never break the action.** The event listener catches any
  throwable and logs a warning instead of failing the login / company creation /
  etc. that triggered it.
- **Reading the log is super-admin only.** Both viewer routes sit behind
  `larafoundry.admin` plus OTP, and `ActivityLogPolicy` gates the model to
  super-admins. The log is global with no tenant scoping by design (a platform
  operator reads it whole).
- **The geo job will not clobber good data.** If the write in the job fails after
  the resolver already produced a real country, its `failed()` path only stamps
  "Unknown" for a row that was never enriched (`geo_updated_at` still null).

## Testing

The suite lives in `tests/Feature/ActivityLog/` and uses `RefreshDatabase`.
Notable files:

- `ActivityLogServiceTest`: the write path, causer/subject distinction, email
  resolution, sync vs async geo dispatch.
- `EventRegistryTest`: the registry accessor and that registered events are logged.
- `GeoEnrichmentTest`: the resolver's private-IP shortcut, caching, fail-soft
  behaviour, and the job's enrichment / `failed()` guard.
- `ModelAuditTest`: the `LogsActivity` trait's created/updated/deleted diffs.
- `AdminViewerTest`: the super-admin viewer, the `hours` allow-list, per-user
  scoping, and the auth gate.
- `Phase1bAuditLogTest`: the completeness additions - RBAC role CRUD, self-service
  profile/password edits, the admin-access-failure and file-upload events, and the
  admin-editable console screens.

Run them with Pest:

```bash
composer test
```

## What changed from the early draft

If you arrived from the older [modules/logging.md](modules/logging.md), these
things changed on the way to the shipped package:

| Early draft | Shipped |
|-------------|---------|
| `CustomActivity` model | `ActivityLog\Models\Activity` (extends spatie's `Activity`), bound into `activitylog.activity_model` |
| `ActivityLogServiceProvider` maps 60+ events in a `$events` array | A config registry (`larafoundry-activitylog.events`) read by `EventLogRegistry`; `LogRegisteredEvents` subscribes it. Core ships auth/tenancy/RBAC/media/tickets/notification events; the host adds domain events by publishing the config |
| `logActivity()` / `logMethodExecution()` statics | An injectable `ActivityLogService` (via the `Activity` facade); methods are `log()`, `logEvent()`, `logMethodExecution()` |
| `GetGeoDataByIpAction` (invokable) | A swappable `GeoResolver` contract, default `IpApiGeoResolver`; enrichment via the `RetrieveActivityGeoData` job |
| Device via `jenssegers/agent` directly | The core's phase-1.1 `DeviceFingerprintResolver` contract (one source of device data) |
| Causer and subject share the user id | Causer and subject are distinct; the causer is never its own subject |
| Raw URLs and properties stored verbatim | Query-string, route-path and property PII redacted before storage |
| `/admin/logs/{user}` and `/admin/generalLogs` | `admin.activity-log.user` (`admin/activity-log/users/{user}`) and `admin.activity-log.index` (`admin/activity-log`) |
| Bundled log-viewer, Telescope, Slack/Monolog file channels, admin Telegram/email login alerts | Out of scope here; the shipped module is the DB activity log only |
