# Notifications

The notifications layer gives every user an in-app notification centre (a bell in
the header plus a paginated inbox) and gives the super-admin a broadcast tool that
fans a single message out to a filtered slice of users. Delivery is in-app and,
optionally, email; both ride the database queue, so no Redis or daemon is needed.
A host adds one trait, runs the migrations, and sends its own notifications through
a single service call.

This is the current, accurate reference for the shipped package. An older planning
draft lives at [modules/notifications.md](modules/notifications.md); it predates the
build and uses names that changed (see
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

Notifications ship with the core package; there is nothing extra to require. The
host opts in by:

1. Adding the `HasNotifications` trait to its `User` model. It sits next to
   Laravel's `Notifiable` (which the core keeps for mail), and exposes the inbox
   relation under the name `appNotifications()` so the two never collide.
2. Running the migrations the package contributes (the `larafoundry_notifications`
   table and the `larafoundry_notification_user` recipient pivot). They load
   automatically.
3. Publishing the Inertia pages so the inbox and the broadcast console resolve in
   the host: `php artisan vendor:publish --tag=larafoundry-pages`.

```php
// app/Models/User.php
use Dmitryisaenko\LaraFoundry\Notifications\Concerns\HasNotifications;

class User extends Authenticatable
{
    use HasNotifications;
}
```

The bell and the admin "Broadcasts" menu item are already wired into the core
layouts and menu providers, so there is no frontend plumbing to do: a host that
renders through the core `LayoutSwitcher` gets the bell in its header and the
operator console gets the broadcast screen in its sidebar.

To register host-specific visual types, publish the config and edit `types`:

```bash
php artisan vendor:publish --tag=larafoundry-notifications-config
```

## Configuration

All settings live in `config/larafoundry-notifications.php`:

```php
return [
    'types' => [
        'info' => ['style' => 'info', 'icon' => 'info'],
        'warning' => ['style' => 'warning', 'icon' => 'warning'],
        'success' => ['style' => 'success', 'icon' => 'check'],
    ],
    'per_page' => env('LARAFOUNDRY_NOTIFICATIONS_PER_PAGE', 15),
    'pullout_limit' => 5,
    'broadcast' => ['batch_size' => 1000],
    'retention' => ['enabled' => true, 'read_lifetime_days' => 30],
    'channels' => ['database', 'mail'],
];
```

| Key | Default | What it does |
|-----|---------|--------------|
| `types` | info, warning, success | The visual type registry. A notification's `code` maps to one of these for the colour and icon the frontend renders. Mirrors the permissions and activity-log catalogs: a host publishes the file and adds its own. System notifications always render in the `system` style regardless. |
| `per_page` | `15` | Page size of the full inbox. |
| `pullout_limit` | `5` | How many recent items the bell dropdown shows. |
| `broadcast.batch_size` | `1000` | A broadcast attaches its audience asynchronously in chunks of this size, so a large user base never blocks the request or runs one giant insert. |
| `retention.read_lifetime_days` | `30` | `larafoundry:notifications-prune` deletes read pivot rows older than this, then any notification left with no recipients. Set `retention.enabled` to `false` to keep everything. |
| `channels` | database, mail | The delivery channels v1 ships. A webhook channel and per-user channel preferences are deferred; this key is the seam they will plug into. |

## Usage

### Sending a system notification from your domain

When something happens in the host's domain (an order ships, a ticket is answered),
the host pushes a notification through `NotificationService` rather than writing
rows by hand. Wording is passed as translation keys, so each recipient reads it in
their own locale and the host owns the text in its lang files.

```php
use Dmitryisaenko\LaraFoundry\Notifications\Support\NotificationService;

app(NotificationService::class)->system(
    users: $company->users,            // user models or ids; deduped
    code: 'success',                   // a key from the `types` registry
    titleKey: 'Your order :ref shipped',
    bodyKey: 'Track it from your dashboard.',
    params: ['ref' => $order->reference],
    data: ['actions' => [
        ['label' => 'View order', 'url' => "/orders/{$order->id}"],
    ]],
);
```

`data.actions` render as internal links in the inbox (see
[Security notes](#security-notes)). `params` fills the `:placeholders` in the
translation keys.

### The bell and the inbox

The bell lives in the core header (both the tenant and admin shells). It refreshes
the unread badge on a light interval that only ticks while the tab is visible, and
loads the recent list on open, not continuously, so it stays cheap on shared
hosting. "View all" links to the full inbox at `/notifications`. Both the bell and
the inbox render every title and body as plain text.

### Super-admin broadcasts

In the operator console, "Broadcasts" is a draft-then-send workflow. The admin
writes per-locale titles and bodies, picks a recipient filter, and optionally a
visibility window. Sending flips the draft to `sending` and queues
`SendBroadcastNotificationJob`, which attaches the audience in chunks and flips the
record to `sent`. Recipient filters are `emailVerified`, `recentActivity` (within
1, 24 or 168 hours) and `role`; the super-admin is always excluded from the
audience.

### Pruning old notifications

The package ships a prune command but does not schedule it (the core targets cron
queues, not a daemon). The host schedules it:

```php
// app/Console/Kernel.php  (or routes/console.php)
$schedule->command('larafoundry:notifications-prune')->daily();
```

## API reference

### `NotificationService` (host seam)

| Method | Signature | Purpose |
|--------|-----------|---------|
| `system()` | `system(iterable $users, string $code, string $titleKey, ?string $bodyKey = null, array $params = [], array $data = []): Notification` | Create one system notification and attach the given users (models or ids, deduped). |

### `HasNotifications` (on the `User` model)

| Method | Returns | Purpose |
|--------|---------|---------|
| `appNotifications()` | `BelongsToMany` | The user's notifications, newest first, with `read_at` on the pivot. |
| `unreadAppNotificationsCount()` | `int` | How many are still unread. |

### `Notification` model

Scopes `admin()`, `system()`, `sent()`, `visible()` (inside the visibility
window); `localizedTitle(?$locale)` / `localizedBody(?$locale)` (system reads the
translation key, an admin broadcast reads the per-locale translations with an
English fallback); `isVisible()`, `isDraft()`, `isAdmin()`; the `users()`
relation (the recipient pivot, carrying `read_at`).

### Routes the host gets

Inbox, behind `web, auth, larafoundry.account.active` (a blocked user has no
inbox), every action scoped to the caller's own notifications:

```
GET  /notifications                 notifications.index         the paginated inbox
GET  /notifications/recent          notifications.recent        recent items for the bell (JSON)
GET  /notifications/unread-count    notifications.unread-count  the unread badge (JSON)
POST /notifications/read-all        notifications.read-all      mark every unread read
POST /notifications/{id}/read       notifications.read          mark one read
```

Broadcast console, behind the admin gate (`web, auth, verified, larafoundry.admin`
plus the OTP step-up), `send` rate-limited:

```
GET    /admin/notifications              admin.notifications.index
GET    /admin/notifications/create       admin.notifications.create
POST   /admin/notifications              admin.notifications.store
GET    /admin/notifications/{id}/edit    admin.notifications.edit
PUT    /admin/notifications/{id}         admin.notifications.update   (draft only)
POST   /admin/notifications/{id}/send    admin.notifications.send     (throttle:10,1)
DELETE /admin/notifications/{id}         admin.notifications.destroy
```

The host does not define these routes; it links to them.

### Events (extension hooks)

| Event | Fired when | Carries |
|-------|-----------|---------|
| `BroadcastNotificationSent` | a broadcast finishes its fan-out | the `Notification` |

Under `Dmitryisaenko\LaraFoundry\Notifications\Events\`. It is in the activity-log
event registry, so a sent broadcast is auditable out of the box.

### Console

`larafoundry:notifications-prune` deletes read pivots older than the retention
window and orphaned sent notifications; drafts are always kept.

## Security notes

Notifications are built so a message can never become an attack surface:

- **A user sees and mutates only their own.** Every inbox action goes through
  `appNotifications()` on the authenticated user, so referencing another user's
  notification id simply finds nothing (a cross-user read returns 404). The bulk
  "mark all read" touches only the caller's pivot rows.
- **Titles and bodies are plain text.** The inbox and bell render them as text,
  never `v-html`, so stored wording can never inject markup.
- **Actions are internal GET links only.** `data.actions` is reduced to entries
  whose `url` is a same-origin relative path: a single leading slash, never a
  protocol-relative `//host`, and never a backslash (a browser normalises `/\host`
  to an off-site origin). The method is dropped, so an action can never drive a
  POST, DELETE or off-site redirect.
- **The super-admin is never a broadcast recipient.** The fan-out query excludes
  the configured super-admin email, so the operator account does not accumulate
  broadcasts meant for tenants.
- **A broadcast fans out once.** The send job is guarded on the `sending` state and
  the pivot's `(notification_id, user_id)` unique key, so a queue retry can neither
  re-fire the `BroadcastNotificationSent` event nor double-attach a user.
- **A blocked account has no inbox.** The routes sit behind
  `larafoundry.account.active`, so a blocked or deleted user cannot reach them.

## Testing

The suite lives in `tests/Feature/Notifications/` and `tests/Unit/Notifications/`,
with a test `User` that `use`s `HasNotifications`. Notable files:

- `InboxTest`: the inbox shows only the user's own notifications, the unread count,
  marking one or all read, and the cross-user IDOR check (another user's
  notification returns 404).
- `BroadcastTest`: the draft-to-send workflow, the required English title, the
  visibility-window validation, and that a non-draft cannot be edited.
- `BroadcastJobTest`: the chunked fan-out, recipient filtering, super-admin
  exclusion, and the one-shot status guard.
- `NotificationServiceTest`: `system()` creates the row and attaches deduped users.
- `PruneNotificationsTest`: read rows past the window are pruned while recent and
  draft ones are kept.
- `NotificationModelTest` (unit): locale resolution for system and admin types, and
  the visibility scopes.

Run them with Pest:

```bash
composer test
```

## What changed from the early draft

If you arrived from the older [modules/notifications.md](modules/notifications.md),
these names and choices changed on the way to the shipped package:

| Early draft | Shipped |
|-------------|---------|
| `notifications` / `notification_user` tables | `larafoundry_notifications` / `larafoundry_notification_user` (prefixed so they never collide with Laravel's own `notifications` table) |
| `notifications()` relation on the user | `appNotifications()` (so it never collides with `Notifiable::notifications()`) |
| status enum `draft, sent` | `draft, sending, sent` (the `sending` state spans the queued fan-out) |
| demographic targeting (country, sex, age, phone) and an auto-translate button | the core ships `emailVerified` / `recentActivity` / `role`; demographic filters belong to the host and machine translation was dropped |
| user routes `/unread`, `/unread-recent`, `/mark-all-read` | `recent`, `unread-count`, `read-all`, `{id}/read` |
| `config/own.php` and `system_notification_lifetime_days` | `config/larafoundry-notifications.php` and `retention.read_lifetime_days` |
| 30s polling, auto-mark-as-read on expand | a 60s visibility-aware count poll; an explicit mark-read; actions restricted to internal GET links |
