<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LaraFoundry — tickets / helpdesk (phase 4.2)
|--------------------------------------------------------------------------
| The support channel between a host USER and the platform SUPER-ADMIN
| (decision D-4.2-2): a customer opens a ticket, the operator answers from the
| console. It is NOT a tenant feature — tickets carry no company scope.
|
| Categories and labels are CONFIG-driven slug lists, stored on the ticket as
| JSON arrays (decision D-4.2-5) — no separate tables/pivots. A HOST adds its
| own by publishing this file (`vendor:publish --tag=larafoundry-tickets-config`)
| and editing the lists; the matching UI labels are i18n keys
| (`tickets.category.*`, `tickets.label.*`) the host adds to its translations.
|
| Priorities and statuses are also listed here, but they are TIED TO THE STATUS
| WORKFLOW in the controllers (wait-moderator → wait-customer → resolved) — they
| are here for validation and the UI list, not for free host extension.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Categories (host-extensible)
    |--------------------------------------------------------------------------
    | What a ticket is about. The user multi-selects these on creation; the
    | operator can edit them. Slugs only — labels live in i18n
    | (`tickets.category.<slug>`). Stored on the ticket as a JSON slug array.
    */
    'categories' => ['general', 'billing', 'feature', 'bug'],

    /*
    |--------------------------------------------------------------------------
    | Labels (admin-only triage, host-extensible)
    |--------------------------------------------------------------------------
    | Operator-only triage tags (e.g. quick/complex). Never set by the user.
    | Slugs only — labels live in i18n (`tickets.label.<slug>`). Stored on the
    | ticket as a JSON slug array.
    */
    'labels' => ['quick', 'complex'],

    /*
    |--------------------------------------------------------------------------
    | Priorities (workflow-tied)
    |--------------------------------------------------------------------------
    | Used for validation and the UI list. The first value is the default for a
    | user-opened ticket.
    */
    'priorities' => ['standard', 'high'],

    /*
    |--------------------------------------------------------------------------
    | Statuses (workflow-tied — do not reorder/rename without code changes)
    |--------------------------------------------------------------------------
    | wait-moderator — opened by the user / reopened, waiting for the operator.
    | wait-customer  — the operator replied, waiting for the user.
    | resolved       — closed by the operator.
    */
    'statuses' => ['wait-moderator', 'wait-customer', 'resolved'],

    /*
    |--------------------------------------------------------------------------
    | Listing sizes
    |--------------------------------------------------------------------------
    */
    'per_page' => env('LARAFOUNDRY_TICKETS_PER_PAGE', 15),
    'admin_per_page' => env('LARAFOUNDRY_TICKETS_ADMIN_PER_PAGE', 20),

    /*
    |--------------------------------------------------------------------------
    | Resolved-ticket hiding (list-only)
    |--------------------------------------------------------------------------
    | The user's inbox hides a resolved ticket this many days after it was last
    | touched (declutter). It is a LIST filter only — the ticket is still
    | viewable by its owner and a reply reopens it.
    */
    'resolved_hidden_after_days' => env('LARAFOUNDRY_TICKETS_RESOLVED_HIDDEN_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Rate limits (security finding S2)
    |--------------------------------------------------------------------------
    | Throttle strings ("attempts,minutes") applied to the user create/reply
    | endpoints so a script cannot flood the support queue. The donor had none.
    */
    'rate_limit' => [
        'create' => env('LARAFOUNDRY_TICKETS_RATE_CREATE', '5,1'),
        'reply' => env('LARAFOUNDRY_TICKETS_RATE_REPLY', '20,1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications (phase 4.1 integration, decision D-4.2-3)
    |--------------------------------------------------------------------------
    | When the operator replies to / opens a ticket, push an in-app system
    | notification to the ticket's author through the core NotificationService.
    | Set false to silence it (e.g. a host wiring its own channel).
    */
    'notifications' => [
        'enabled' => env('LARAFOUNDRY_TICKETS_NOTIFICATIONS', true),
    ],

];
