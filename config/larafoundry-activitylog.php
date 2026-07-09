<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\ActivityLog\Geo\IpApiGeoResolver;
use Dmitryisaenko\LaraFoundry\Auth\Events\AdminAccessAttemptFailed;
use Dmitryisaenko\LaraFoundry\Auth\Events\PasswordUpdated;
use Dmitryisaenko\LaraFoundry\Auth\Events\ProfileUpdated;
use Dmitryisaenko\LaraFoundry\Authorization\Events\RoleCreated;
use Dmitryisaenko\LaraFoundry\Authorization\Events\RoleDeleted;
use Dmitryisaenko\LaraFoundry\Authorization\Events\RoleUpdated;
use Dmitryisaenko\LaraFoundry\Media\Events\FileUploaded;
use Dmitryisaenko\LaraFoundry\Notifications\Events\BroadcastNotificationSent;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyArchived;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyCreated;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyInvitationSent;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyUnarchived;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRemovalCancelled;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRemovalRejected;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRemovalRequested;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRemoved;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\EmployeeRoleChanged;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\InvitationAccepted;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\InvitationRejected;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\InvitationResent;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\InvitationWithdrawn;
use Dmitryisaenko\LaraFoundry\Tickets\Events\TicketCreated;
use Dmitryisaenko\LaraFoundry\Tickets\Events\TicketReplied;
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
    | entry's properties. The core ships its auth, tenancy, RBAC, media, tickets and
    | notification events; the host adds its own domain events the same way.
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
        // Phase 1 (activity completeness): in-session profile/password edits + the
        // super-admin access-failure signal (previously fired but unlogged).
        ProfileUpdated::class => ['group' => 'Auth', 'description' => 'Profile updated', 'code' => 200],
        PasswordUpdated::class => ['group' => 'Auth', 'description' => 'Password changed', 'code' => 200],
        AdminAccessAttemptFailed::class => ['group' => 'Auth', 'description' => 'Admin access attempt failed', 'code' => 401],

        // --- Authorization / RBAC (phase 1, activity completeness) ---
        RoleCreated::class => ['group' => 'Authorization', 'description' => 'Role created', 'code' => 201],
        RoleUpdated::class => ['group' => 'Authorization', 'description' => 'Role updated', 'code' => 200],
        RoleDeleted::class => ['group' => 'Authorization', 'description' => 'Role deleted', 'code' => 200],

        // --- Media (phase 2.4; registered phase 1, activity completeness) ---
        FileUploaded::class => ['group' => 'Media', 'description' => 'File uploaded', 'code' => 201],

        // --- Tenancy (phase 1.2) — the core's own events ---
        CompanyCreated::class => ['group' => 'Tenancy', 'description' => 'Company created', 'code' => 201],
        CompanyInvitationSent::class => ['group' => 'Tenancy', 'description' => 'Employee invited', 'code' => 200],
        EmployeeRemoved::class => ['group' => 'Tenancy', 'description' => 'Employee removed', 'code' => 200],
        CompanyArchived::class => ['group' => 'Tenancy', 'description' => 'Company archived', 'code' => 200],
        CompanyUnarchived::class => ['group' => 'Tenancy', 'description' => 'Company unarchived', 'code' => 200],
        // Owner-employee lifecycle (phase 1, activity completeness).
        InvitationAccepted::class => ['group' => 'Tenancy', 'description' => 'Invitation accepted', 'code' => 201],
        InvitationRejected::class => ['group' => 'Tenancy', 'description' => 'Invitation rejected', 'code' => 200],
        InvitationWithdrawn::class => ['group' => 'Tenancy', 'description' => 'Invitation withdrawn', 'code' => 200],
        InvitationResent::class => ['group' => 'Tenancy', 'description' => 'Invitation resent', 'code' => 200],
        EmployeeRemovalRequested::class => ['group' => 'Tenancy', 'description' => 'Employee removal requested', 'code' => 200],
        EmployeeRemovalCancelled::class => ['group' => 'Tenancy', 'description' => 'Employee removal cancelled', 'code' => 200],
        EmployeeRemovalRejected::class => ['group' => 'Tenancy', 'description' => 'Employee removal rejected', 'code' => 200],
        EmployeeRoleChanged::class => ['group' => 'Tenancy', 'description' => 'Employee role changed', 'code' => 200],

        // --- Notifications (phase 4.1) ---
        BroadcastNotificationSent::class => ['group' => 'Notifications', 'description' => 'Broadcast sent', 'code' => 200],

        // --- Tickets / helpdesk (phase 4.2) ---
        TicketCreated::class => ['group' => 'Tickets', 'description' => 'Ticket created', 'code' => 201],
        TicketReplied::class => ['group' => 'Tickets', 'description' => 'Ticket replied', 'code' => 200],

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
