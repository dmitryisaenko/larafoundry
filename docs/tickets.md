# Tickets

The tickets layer gives every user a support helpdesk: they open a ticket, the
platform operator answers it from the admin console, and the conversation runs as
a thread. The ticket status is derived from who replied, never chosen by hand. A
host adds one trait, runs the migrations, and gets the customer pages and the
operator console with no further wiring.

Tickets are a support channel between a host user and the platform super-admin.
They are not a tenant feature: a ticket carries no company scope, and the operator
sees every user's tickets in one queue.

This is the current, accurate reference for the shipped package. An older planning
draft lives at [modules/tickets.md](modules/tickets.md); it predates the build and
uses names and tables that changed (see
[What changed](#what-changed-from-the-early-draft)).

## Contents

- [Install](#install)
- [Configuration](#configuration)
- [Usage](#usage)
- [API reference](#api-reference)
- [Security notes](#security-notes)
- [Testing](#testing)
- [What changed from the early draft](#what-changed-from-the-early-draft)

## Install

Tickets ship with the core package; there is nothing extra to require. The host
opts in by:

1. Adding the `HasTickets` trait to its `User` model. It exposes the author
   relation under the name `tickets()`, alongside the other core traits.
2. Running the migrations the package contributes (the `larafoundry_tickets`
   table and the `larafoundry_ticket_messages` conversation table). They load
   automatically.
3. Publishing the Inertia pages so the customer pages and the operator console
   resolve in the host: `php artisan vendor:publish --tag=larafoundry-pages`.

```php
// app/Models/User.php
use Dmitryisaenko\LaraFoundry\Tickets\Concerns\HasTickets;

class User extends Authenticatable
{
    use HasTickets;
}
```

The "Support" link in the user header and the "Support" item in the operator
console are already wired into the core layouts and menu providers, so there is no
frontend plumbing to do.

To change the category and label lists, publish the config and edit it:

```bash
php artisan vendor:publish --tag=larafoundry-tickets-config
```

## Configuration

All settings live in `config/larafoundry-tickets.php`:

```php
return [
    'categories' => ['general', 'billing', 'feature', 'bug'],
    'labels' => ['quick', 'complex'],
    'priorities' => ['standard', 'high'],
    'statuses' => ['wait-moderator', 'wait-customer', 'resolved'],
    'per_page' => env('LARAFOUNDRY_TICKETS_PER_PAGE', 15),
    'admin_per_page' => env('LARAFOUNDRY_TICKETS_ADMIN_PER_PAGE', 20),
    'resolved_hidden_after_days' => env('LARAFOUNDRY_TICKETS_RESOLVED_HIDDEN_DAYS', 7),
    'rate_limit' => ['create' => '5,1', 'reply' => '20,1'],
    'notifications' => ['enabled' => true],
];
```

| Key | Default | What it does |
|-----|---------|--------------|
| `categories` | general, billing, feature, bug | What a ticket is about. The user multi-selects these on creation; the operator can edit them. Slugs only, stored on the ticket as a JSON array. The UI label for each slug is the i18n key `tickets.category.<slug>`, which the host adds to its translations. Host-extensible: edit the list, no migration. |
| `labels` | quick, complex | Operator-only triage tags, never set by the user. Same JSON-slug shape as categories; UI labels are `tickets.label.<slug>`. |
| `priorities` | standard, high | Used for validation and the UI list. The first value is the default for a user-opened ticket. Tied to the workflow, not free host extension. |
| `statuses` | wait-moderator, wait-customer, resolved | The workflow states. They are tied to the controller transitions, so do not reorder or rename them without code changes. |
| `per_page` / `admin_per_page` | `15` / `20` | Page size of the user inbox and the operator queue. |
| `resolved_hidden_after_days` | `7` | The user inbox hides a resolved ticket this many days after it was last touched, to declutter. It is a list filter only: the owner can still open it, and a reply reopens it. |
| `rate_limit.create` / `rate_limit.reply` | `5,1` / `20,1` | Throttle strings (`attempts,minutes`) on the user create and reply endpoints, so a script cannot flood the support queue. |
| `notifications.enabled` | `true` | When the operator opens or answers a ticket, push an in-app notification to the author through the core `NotificationService` (phase 4.1). Set `false` to silence it, for example when a host wires its own channel. |

## Usage

### The status nobody sets

A ticket has three statuses and you never pick one. The status is derived from who
acted:

```
  user opens a ticket
        |
        v
  wait-moderator  <-----------------------+
        |                                 |
        | operator replies                | user replies
        v                                 |
  wait-customer  --------------------------+
        |
        | operator resolves
        v
  resolved  ----> user replies ----> reopens (wait-moderator)
```

A user reply always sets `wait-moderator` (it needs the operator again), an
operator reply always sets `wait-customer`, and a reply to a resolved ticket
reopens it. The same derived status drives the operator queue ordering through the
`workflowOrder()` scope: open before resolved, brand-new before answered, high
priority first, most recently updated last.

### The customer side

A user reaches their tickets from the "Support" link in the header. They see only
their own tickets (resolved ones drop off the list after `resolved_hidden_after_days`),
open new ones, and reply in the thread. URLs use the ticket uuid, never the
sequential id.

### The operator side

In the operator console, "Support" opens the queue with stat cards (total, open,
high priority) and filters (search, status, priority; the default view is the open
queue). From a ticket the operator replies (which notifies the author and moves the
ticket to `wait-customer`), toggles categories and labels, sets priority, and
resolves. Status, priority, category and label changes are written to the activity
log. The operator can also open a ticket on a user's behalf, picking the user
through the admin user search; that ticket starts `wait-customer` and notifies the
user.

### Notifying the author

The operator reply does not grow its own notification logic; it calls the phase 4.1
`NotificationService` seam (when `notifications.enabled`). The author gets an in-app
notification, localised to their language, with a link back to the ticket.

## API reference

### `HasTickets` (on the `User` model)

| Method | Returns | Purpose |
|--------|---------|---------|
| `tickets()` | `HasMany` | The user's own tickets, newest first. |

### `Ticket` model

Table `larafoundry_tickets`. Constants `STATUS_WAIT_MODERATOR`,
`STATUS_WAIT_CUSTOMER`, `STATUS_RESOLVED`. `categories` and `labels` cast to
arrays; a uuid is assigned on creation. Relations `user()` (the author, resolved
from `config('auth.providers.users.model')`) and `messages()` (the conversation,
oldest first). Scopes `excludeOldResolved(?$days)` (hide stale resolved tickets)
and `workflowOrder()` (the queue ordering above). Helpers `isOpen()`,
`isResolved()`, `isNew()`. Uses the core `Filterable` trait.

### Routes the host gets

Customer side, behind `web, auth` only. There is deliberately no account-active or
verified gate (see [Security notes](#security-notes)). Every action is scoped to
the caller's own tickets, and the route key is the uuid:

```
GET  /tickets                     tickets.index           the user's own tickets
GET  /tickets/create              tickets.create          the new-ticket form
POST /tickets                     tickets.store           open a ticket   (throttle: rate_limit.create)
GET  /tickets/{ticket:uuid}       tickets.show            view one of the user's tickets
POST /tickets/{ticket:uuid}/messages  tickets.messages.store  reply  (throttle: rate_limit.reply)
```

Operator side, behind the admin gate (`web, auth, verified, larafoundry.admin`
plus the OTP step-up):

```
GET    /admin/tickets                    admin.tickets.index
GET    /admin/tickets/create             admin.tickets.create
POST   /admin/tickets                    admin.tickets.store
GET    /admin/tickets/{ticket}           admin.tickets.show
GET    /admin/tickets/{ticket}/edit      admin.tickets.edit
PUT    /admin/tickets/{ticket}           admin.tickets.update
POST   /admin/tickets/{ticket}/reply     admin.tickets.reply
PATCH  /admin/tickets/{ticket}/close     admin.tickets.close
PATCH  /admin/tickets/{ticket}/priority  admin.tickets.priority
POST   /admin/tickets/{ticket}/category  admin.tickets.category   (toggle)
POST   /admin/tickets/{ticket}/label     admin.tickets.label      (toggle)
```

The host does not define these routes; it links to them. The operator routes key
on the integer id (behind the admin gate); the customer routes key on the uuid.

### Authorization (`TicketPolicy`)

Customer actions go through the policy: `viewAny` is allowed, `view` and `reply`
require ownership, `update` requires ownership and an open ticket, and `delete`
and `close` are denied to everyone (a user never closes or deletes a ticket; the
operator resolves through the admin-gated console). Ownership is a string
comparison of the author key, so it holds for integer, uuid or ulid user keys.

### Events (extension hooks)

| Event | Fired when | Carries |
|-------|-----------|---------|
| `TicketCreated` | a user opens a ticket | the user and the ticket |
| `TicketReplied` | a reply is posted | the user, the ticket, and whether the operator posted it |

Both under `Dmitryisaenko\LaraFoundry\Tickets\Events\`. They are in the activity-log
event registry, so opened and answered tickets are auditable out of the box. The
ticket body is not logged.

## Security notes

The donor this module was extracted from had no rate limiting, an inverted queue
filter and a query that used an invalid SQL operator. The shipped module closes
those and was reviewed against this list:

- **A user sees and mutates only their own tickets.** Every customer action is
  authorised by the ownership policy, so asking for another user's ticket is a 403,
  not a peek. The route key is the uuid, so the URL never reveals how many tickets
  exist.
- **Message bodies are plain text.** The thread renders every message as text,
  never `v-html`, so a user-submitted body cannot inject markup into the operator's
  console (a stored-XSS path straight at the admin).
- **The operator's name is not leaked.** The customer-facing payload reports an
  operator reply as coming from "Support team", not from a named person.
- **Open and reply are throttled.** The create and reply endpoints carry the
  `rate_limit` throttles, so the support queue cannot be flooded.
- **Filters reject unknown values.** The operator status and priority filters
  ignore any value not in the configured lists, so a typo or a crafted query falls
  back to the open queue rather than emptying it or leaking resolved tickets.
- **Support stays reachable, by design.** The customer routes carry `auth` only,
  with no account-active gate, so a user whose company is suspended (for billing,
  say) can still reach support: it is their only channel back to the operator. This
  is the deliberate opposite of the notifications inbox. Note the boundary: an
  identity that a host hard-blocks at the account level (a banned or deleted
  account, enforced by a global middleware such as the core
  `EnsureAccountIsActive`) is still stopped on every route, support included. A
  suspended company and a banned account are two different kinds of blocked.

## Testing

The suite lives in `tests/Feature/Tickets/` and `tests/Unit/Tickets/`, with a test
`User` that `use`s `HasTickets`. Notable files:

- `UserTicketTest`: a user lists only their own tickets, opens one in
  `wait-moderator`, the cross-user IDOR check (another user's ticket returns 403),
  reply reopens a resolved ticket, a blocked user can still reach support, and the
  create throttle.
- `AdminTicketTest`: the operator queue (open by default, resolved hidden), the
  filters, opening a ticket for a user with a notification, the operator reply
  moving the ticket to `wait-customer` and notifying the author, closing and the
  audit entry, and the category and priority toggles.
- `TicketModelTest` (unit): the status helpers, the scopes, and the JSON casts.

A host integration test (`tests/Feature/TicketsIntegrationTest.php` in the host app)
drives the real middleware stack: the trait relation, the IDOR 403, the
unverified-still-reaches-support case, and the operator reply notifying the author
end to end.

Run them with Pest:

```bash
composer test
```

## What changed from the early draft

If you arrived from the older [modules/tickets.md](modules/tickets.md), these names
and choices changed on the way to the shipped package:

| Early draft | Shipped |
|-------------|---------|
| `tickets` + `ticket_messages` and four category/label tables and pivots | `larafoundry_tickets` + `larafoundry_ticket_messages`; categories and labels are JSON slug arrays on the ticket, driven by config, no tables or pivots |
| `is_resolved`, `is_locked`, `assigned_to` columns | dropped; status is the single source of truth and there is no assignee |
| `coderflex/laravel-ticket` base package | cut; the model is self-contained |
| `config/laravel_ticket.php` | `config/larafoundry-tickets.php` |
| events `TicketCreate` / `TicketAnswerCreate` | `TicketCreated` / `TicketReplied` (the latter carries whether the operator posted it) |
| admin create at `/admin/tickets/create/{user}` | `/admin/tickets/create` with an admin user search to pick the customer |
| `MessageList.vue` and per-view components, grid/list toggle | a shared `TicketMessageList` and the published Inertia pages; the operator queue is a single list with stat cards and filters |
| no rate limiting; an inverted `show_tickets` filter; a query using `'!=='` | throttled create and reply; the queue defaults to open and validates filter values; the invalid-operator method removed |
