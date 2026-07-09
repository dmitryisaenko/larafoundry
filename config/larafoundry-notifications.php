<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LaraFoundry — notifications (phase 4.1)
|--------------------------------------------------------------------------
| The in-app notification centre + super-admin broadcasts. Delivery is in-app
| (the `larafoundry_notifications` store) and email (translation-key
| Notification classes, the core's mail pattern); both ride the database queue,
| so no Redis/daemon is required.
|
| The `types` registry mirrors the permissions / activity-log catalogs: the
| core ships the universal visual types, and a HOST adds its own by publishing
| this file (`vendor:publish --tag=larafoundry-notifications-config`) and
| editing `types`. Each key is a `code` a notification carries; the frontend
| maps it to a style + icon.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Visual type registry (code => style + icon)
    |--------------------------------------------------------------------------
    | A notification's `code` resolves to one of these for colour/icon in the
    | UI. System notifications fall back to the 'system' style regardless.
    */
    'types' => [
        'info' => ['style' => 'info', 'icon' => 'info'],
        'warning' => ['style' => 'warning', 'icon' => 'warning'],
        'success' => ['style' => 'success', 'icon' => 'check'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Listing sizes
    |--------------------------------------------------------------------------
    | `per_page` paginates the inbox; `pullout_limit` caps the bell dropdown.
    */
    'per_page' => env('LARAFOUNDRY_NOTIFICATIONS_PER_PAGE', 15),
    'pullout_limit' => 5,

    /*
    |--------------------------------------------------------------------------
    | Broadcast delivery
    |--------------------------------------------------------------------------
    | A super-admin broadcast attaches its audience asynchronously
    | (SendBroadcastNotificationJob) in chunks of `batch_size` so a large user
    | base never blocks the request or runs one giant insert.
    */
    'broadcast' => [
        'batch_size' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    | `notifications:prune` deletes READ pivot rows older than
    | `read_lifetime_days`, and any notification left with no recipients. Like
    | the activity-log clean, it is NOT scheduled automatically — the host
    | schedules it (the core targets cron queues), e.g.
    | `$schedule->command('larafoundry:notifications-prune')->daily();`.
    */
    'retention' => [
        'enabled' => true,
        'read_lifetime_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Channels (seam)
    |--------------------------------------------------------------------------
    | v1 delivers in-app + email. A webhook channel and per-user channel
    | preferences are deliberately deferred (SSRF surface / scope); this key is
    | the documented seam they will plug into.
    */
    'channels' => ['database', 'mail'],

    /*
    |--------------------------------------------------------------------------
    | Owner-employee lifecycle wiring (phase 2a)
    |--------------------------------------------------------------------------
    | Master switch for the in-app + email notifications emitted across the
    | owner-employee lifecycle (invites, joins, removals, role changes, company
    | created/archived). When false, the lifecycle listeners do not subscribe, so
    | a host can silence the whole set without touching the underlying events
    | (which still fire and still feed the activity log). Mirrors the tickets
    | `notifications.enabled` gate.
    */
    'lifecycle' => [
        'enabled' => env('LARAFOUNDRY_LIFECYCLE_NOTIFICATIONS_ENABLED', true),
    ],

];
