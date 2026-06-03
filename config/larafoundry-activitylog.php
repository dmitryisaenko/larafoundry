<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\ActivityLog\Geo\IpApiGeoResolver;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyCreated;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyInvitationSent;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRemoved;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;

/*
|--------------------------------------------------------------------------
| LaraFoundry — activity log (phase 2.1)
|--------------------------------------------------------------------------
| The platform activity log is a SUPER-ADMIN tool, not a tenant feature
| (decision D2.1-0): a platform operator reads it globally, so there is no
| company scoping.
|
| The `events` registry follows the same pattern as the permissions catalog
| (`config/larafoundry-permissions.php`): the CORE ships only events for its own
| modules (auth, tenancy). DOMAIN events (orders, warehouse, tickets, …) are
| HOST territory — publish this file
| (`vendor:publish --tag=larafoundry-activitylog`) and add your event classes
| here. The merge is additive: event-class keys are unique, so the host's
| entries sit alongside the core's.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    | When false, the registered-event listener does not subscribe and the
    | service does not write. Spatie's own `enabled` flag is separate.
    */
    'enabled' => env('LARAFOUNDRY_ACTIVITY_LOG_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Event registry
    |--------------------------------------------------------------------------
    | EventClass => ['group' => 'Auth', 'description' => '...', 'code' => 200]
    |
    | Each registered event is logged automatically. If the event object exposes
    | a `getLogProperties(): array` method, its return value is merged into the
    | entry's properties. The core ships its auth + tenancy events; RBAC has no
    | event classes (phase 1.3 emits none), so there is nothing to register there
    | yet — the host adds domain events the same way.
    */
    'events' => [

        // --- Auth (phase 1.1) — Laravel's standard auth events ---
        Registered::class => ['group' => 'Auth', 'description' => 'User registered', 'code' => 201],
        Login::class => ['group' => 'Auth', 'description' => 'User logged in', 'code' => 200],
        // NOTE: this captures the standard session logout (SessionGuard fires it).
        // It does NOT cover "log out other devices" (sessions are deleted directly,
        // bypassing the guard) nor future token/API logout (phase 6) — those revoke
        // without firing this event. Add coverage there when phase 6 lands.
        Logout::class => ['group' => 'Auth', 'description' => 'User logged out', 'code' => 200],
        Failed::class => ['group' => 'Auth', 'description' => 'Login failed', 'code' => 401],
        PasswordReset::class => ['group' => 'Auth', 'description' => 'Password reset', 'code' => 200],

        // --- Tenancy (phase 1.2) — the core's own events ---
        CompanyCreated::class => ['group' => 'Tenancy', 'description' => 'Company created', 'code' => 201],
        CompanyInvitationSent::class => ['group' => 'Tenancy', 'description' => 'Employee invited', 'code' => 200],
        EmployeeRemoved::class => ['group' => 'Tenancy', 'description' => 'Employee removed', 'code' => 200],

    ],

    /*
    |--------------------------------------------------------------------------
    | Geolocation enrichment
    |--------------------------------------------------------------------------
    | Looking up a country/city from the visitor's IP is an OUTBOUND call to a
    | third party (ip-api.com by default) carrying the user's IP. It is opt-out
    | for privacy: set `enabled` to false and geo columns stay null. Local /
    | private IPs are never sent out. The resolver is swappable.
    */
    'geo' => [
        'enabled' => env('LARAFOUNDRY_ACTIVITY_GEO_ENABLED', true),
        'resolver' => IpApiGeoResolver::class,
        'cache_ttl' => 24 * 60 * 60,
        'timeout' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Route-access middleware (opt-in)
    |--------------------------------------------------------------------------
    | The `larafoundry.activity.route` middleware logs every request it wraps.
    | It is NOISY and adds a write per request, so it is OFF by default. Enable
    | it deliberately and exclude high-frequency / polling routes by name.
    */
    'route_middleware' => [
        'enabled' => env('LARAFOUNDRY_ACTIVITY_ROUTE_LOG_ENABLED', false),
        'excluded' => [
            'sanctum.*',
            'ignition.*',
            'horizon.*',
            'telescope.*',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    | The core maps this onto spatie's `delete_records_older_than_days`, so it is
    | the value spatie's `activitylog:clean` command prunes by. The command is
    | NOT scheduled automatically — the host schedules it (the core targets cron
    | queues), e.g. `$schedule->command('activitylog:clean')->daily();`.
    */
    'retention_days' => env('LARAFOUNDRY_ACTIVITY_RETENTION_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | PII redaction
    |--------------------------------------------------------------------------
    | Query-string keys whose values are masked before storing `full_url`, and
    | property keys masked before storing `properties`. The donor stored raw
    | URLs and properties — a reset token or 2FA code in a query string would
    | have been written verbatim. Matching is case-insensitive.
    */
    'pii_redact_keys' => [
        'password',
        'password_confirmation',
        'token',
        'api_token',
        'access_token',
        'secret',
        'code',
        'otp',
        'two_factor_code',
        'recovery_code',
        '_token',
        'signature',
    ],

];
