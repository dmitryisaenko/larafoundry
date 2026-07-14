# Admin: Users

The users screen is the first populated view of the operator console: a
super-admin's table of every account on the platform, with search, filtering,
create/edit, block-with-reason, reversible soft-delete, forced email/phone
verification and session-based impersonation. It was extracted and hardened from
a donor `Admin\UserController`, so several of its guarantees exist specifically
to close holes the donor left open (impersonation with no gate and no audit,
block that did not take hold, un-audited state changes).

This is the current, accurate reference for the shipped package. An older planning
draft lives at [modules/admin_users.md](modules/admin_users.md); it predates the
build and describes a different scope (see
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

The console ships with the core package; there is nothing extra to require. All
of it sits behind the super-admin gate, so the only host prerequisites are the
ones the operator console already needs:

1. A configured super-admin identity (`larafoundry.security.super_admin.email`,
   or the `is_admin` flag on the operator account). `VisitorStatus` decides who
   is a super-admin; the `larafoundry.admin` middleware gates every route here.
2. The user model resolved through `config('auth.providers.users.model')`. The
   controller reads that config, so the host's own `User` model is used
   throughout (never a hard-coded class).
3. The activity log enabled, so console actions are recorded. Every mutating
   action writes to the `admin` log.

The routes are registered by the package under the `/admin` prefix and the
`admin.users.*` / `admin.impersonate.*` names. The host does not define them; it
links to them. The Vue pages the controller renders (`Admin/Users/Index`,
`Admin/Users/Edit`, `Admin/Users/Create`) are the core's published pages.

## Configuration

The console settings live under the `admin` key of `config/larafoundry.php`:

```php
'admin' => [
    'users_per_page' => 21,
    'companies_per_page' => 21,
    'subscription_expiring_within_days' => 7,
    'active_within_days' => 30,
    'dashboard_activity_limit' => 10,

    // Host seam (phase 7).
    'user_resource' => AdminUserResource::class,

    // Opt-in personal columns; default empty = privacy-clean.
    'user_columns' => [],
],
```

The keys this module reads:

| Key | Default | What it does |
|-----|---------|--------------|
| `users_per_page` | `21` | Page size for the user list. Read by `UserController::perPage()`. |
| `user_columns` | `[]` (empty) | Which optional personal columns the list serialises and the table renders. Recognised tokens: `phone`, `sex`, `age`, `social`. The default empty array keeps the payload privacy-clean (GDPR-friendly for a public package): no personal column is emitted on the wire, not merely hidden in the UI. Unknown tokens are dropped (intersected with the known set), so a stray or crafted config value never leaks an unrecognised field. |
| `user_resource` | `AdminUserResource::class` | The resource class the console uses to serialise users. A host points this at an `AdminUserResource` subclass to append list columns (see the seam below). A value that does not extend `AdminUserResource` is ignored and the core resource is used. |

Impersonation has no key of its own; it reuses `larafoundry.tenancy.home_route`
(the landing destination after `take`, and the fallback). The super-admin gate
and OTP step-up are configured under `larafoundry.security.super_admin`, not
here.

`payments` is a separate operator screen (`admin.payments.*`, `PaymentController`)
and is not part of this module.

### The opt-in personal columns

`phone`, `sex` and `age` are personal data, so they are off by default. A host
that wants them in the operator table opts in once, in its published config:

```php
// config/larafoundry.php (host copy)
'admin' => [
    'user_columns' => ['phone', 'age'],
],
```

The same token list is shared with the Vue table as the `userColumns` prop, so
the headers, the filters and the serialised payload never drift: a column that
is not opted in has no header, no filter and no value on the wire. The `social`
token lights up the social-links column (phase 3b); it also makes the list
eager-load the `socialLinks` relation so it does not N+1.

## Usage

### The user list

`GET /admin/users` (`admin.users.index`) renders `Admin/Users/Index` with a
paginated, filtered collection. The list carries identity, contact,
verification, account state, the split owned/employee company counts, and
activity timestamps for each user, plus the `userColumns` token list and the
current `filters`. `AdminUsersFilter` drives search and every facet.

`GET /admin/users/search` (`admin.users.search`) is the JSON type-ahead: the same
filter, capped at 20 rows, returned as `{ users: [...] }`.

Filters (each maps to a request key; an empty value is skipped by the base
`Filter`, and each enum facet no-ops on an unrecognised value rather than
collapsing the result set to nothing):

| Request key | Values | Effect |
|-------------|--------|--------|
| `search` | free text | `LIKE` across name, lastname, email and phone. |
| `registered` | `today` / `month` / `year` | Registered on or after that window. |
| `emailVerified` | `verified` / `unverified` | Has / has no `email_verified_at`. |
| `status` | `active` / `blocked` / `deleted` | Not blocked and not deleted / blocked / soft-deleted. |
| `recentActivity` | hours (`1` / `24` / `168`) | `last_activity_at` within the last N hours. |
| `locale` | e.g. `en`, `uk` | Exact interface locale (phase 7). |
| `authType` | `oauth` / `password` | Has / has no `provider_name`. |
| `country` | text | Exact country match (backend always available; the core ships no country registry, so the front shows a plain text filter). |
| `phoneVerified` | `verified` / `unverified` | Has / has no `phone_verified_at`. Front-surfaced only when the `phone` token is on. |
| `sex` | exact value | Exact match. An unknown value simply returns no rows. Front-surfaced only when the `sex` token is on. |
| `ageRange` | `18-25` / `26-35` / `36-45` / `46-59` / `60+` | Coarse, disjoint age bucket, computed as a `birth_date` window (portable across drivers, no age arithmetic in SQL). Front-surfaced only when the `age` token is on. |

### Create and edit

`GET /admin/users/create` and `POST /admin/users` (`create` / `store`);
`GET /admin/users/{user}/edit` and `PUT /admin/users/{user}` (`edit` / `update`).

The forms accept `name`, `lastname`, `middlename`, `email`, `phone`, `country`,
`sex` (a single character), `birth_date`, `password`, `is_admin` and
`social_links`. `StoreUserRequest` validates them; `UpdateUserRequest` extends it
and relaxes two rules for edits (password optional, email uniqueness ignores the
edited row). State columns (`user_blocked_at`, `block_code`, `user_deleted_at`)
are never accepted from input; they are driven only by the block/delete
endpoints. `is_admin` is accepted (granting admin is a legitimate explicit
action) but it is `forceFill`-ed from the validated boolean, never blindly
mass-assigned.

The single-user edit view always carries the personal columns
(phone/sex/age/birth_date) via `AdminUserResource::full()`, independent of the
`user_columns` opt-in: the opt-in gates the LIST, not the deliberate act of
opening one user, and prefilling a blank field there would let a save wipe the
stored value.

### Block, unblock, soft-delete, restore

- `POST /admin/users/{user}/block` (`block`) - stamps `user_blocked_at`, an
  optional numeric `block_code` and a free-text `reason` into
  `user_blocked_status`, then purges the user's tracked sessions so the block
  takes hold immediately.
- `POST /admin/users/{user}/unblock` (`unblock`) - clears all three.
- `DELETE /admin/users/{user}` (`destroy`) - the reversible operator delete:
  stamps `user_deleted_at` and purges sessions. This is not GDPR erasure (that
  is a separate super-admin flow, out of this phase's scope).
- `POST /admin/users/{user}/restore` (`restore`) - clears `user_deleted_at`.

All four write to the `admin` activity log.

### Forced email/phone verification

`POST /admin/users/{user}/verify-email`, `/unverify-email`, `/verify-phone`,
`/unverify-phone` (`verify-email` / `unverify-email` / `verify-phone` /
`unverify-phone`). These are pure operator overrides for support cases: they
stamp or clear `email_verified_at` / `phone_verified_at` and audit it, and they
deliberately send NO verification mail or SMS (the operator is asserting the
contact, not asking the user to confirm it; SMS is out of the core's scope by
decision).

### Impersonation ("follow into a user")

`POST /admin/impersonate/{user}` (`admin.impersonate.take`) stashes the
operator's id in the session, logs in as the target and redirects to the tenancy
home. `POST /admin/impersonate/leave` (`admin.impersonate.leave`) reverses it.
`leave` lives OUTSIDE the `larafoundry.admin` gate (it runs as the impersonated
user, who is not an admin) and is reachable behind `web, auth` only. It is a
self-contained implementation with no third-party dependency; the session key,
not the user model, is the source of truth for "currently impersonating", so a
reload or a new tab stays consistent.

### Per-user links out

The list and edit views link to two other console areas for a given user: the
per-user activity log (`admin.activity-log.user`, `GET /admin/activity-log/users/{user}`)
and creating a support ticket for that user (the admin tickets flow). Those live
in their own modules; this module only links to them.

### Host list columns without forking the table (the seam)

A host that needs extra display columns in the operator table does not touch the
Vue page. It subclasses `AdminUserResource`, overrides `extra()`, and points
`larafoundry.admin.user_resource` at the subclass:

```php
use Dmitryisaenko\LaraFoundry\Admin\Http\Resources\AdminUserResource;
use Illuminate\Http\Request;

class MyUserResource extends AdminUserResource
{
    protected function extra(Request $request): array
    {
        return [
            ['key' => 'demo', 'label' => 'Demo', 'value' => 'Yes', 'badge' => 'emerald'],
        ];
    }
}
```

```php
// config/larafoundry.php (host copy)
'admin' => [
    'user_resource' => \App\Http\Resources\MyUserResource::class,
],
```

Each cell is a plain display descriptor - `key` (stable column id, also the i18n
key for the label), `label`, `value` (already resolved text), and an optional
`badge` colour token (`emerald` / `amber` / `rose` / `slate`). The core emits an
empty `extra_columns`; the table renders whatever cells it finds. Keep `extra()`
to display cells - never expose secrets or heavy relations through it.

## API reference

### Routes (`admin.users.*`, super-admin gated)

| Name | Verb + URI | Action |
|------|-----------|--------|
| `admin.users.index` | `GET /admin/users` | Paginated, filtered list. |
| `admin.users.search` | `GET /admin/users/search` | JSON type-ahead (max 20). |
| `admin.users.create` | `GET /admin/users/create` | Create form. |
| `admin.users.store` | `POST /admin/users` | Persist a new user. |
| `admin.users.edit` | `GET /admin/users/{user}/edit` | Edit form. |
| `admin.users.update` | `PUT /admin/users/{user}` | Update a user. |
| `admin.users.block` | `POST /admin/users/{user}/block` | Block with optional code + reason. |
| `admin.users.unblock` | `POST /admin/users/{user}/unblock` | Unblock. |
| `admin.users.verify-email` | `POST /admin/users/{user}/verify-email` | Force email verified. |
| `admin.users.unverify-email` | `POST /admin/users/{user}/unverify-email` | Clear email verification. |
| `admin.users.verify-phone` | `POST /admin/users/{user}/verify-phone` | Force phone verified. |
| `admin.users.unverify-phone` | `POST /admin/users/{user}/unverify-phone` | Clear phone verification. |
| `admin.users.destroy` | `DELETE /admin/users/{user}` | Reversible soft-delete. |
| `admin.users.restore` | `POST /admin/users/{user}/restore` | Restore a soft-deleted user. |
| `admin.impersonate.take` | `POST /admin/impersonate/{user}` | Start impersonating (gated). |
| `admin.impersonate.leave` | `POST /admin/impersonate/leave` | Stop impersonating (outside the admin gate). |

All the `admin.users.*` routes and `impersonate.take` sit behind
`web, auth, verified, larafoundry.admin` plus the OTP step-up gate
(`larafoundry.admin.otp`). `impersonate.leave` runs behind `web, auth` only.

### `AdminUserResource`

Serialises one user for the console. Key methods and members:

| Member | Purpose |
|--------|---------|
| `COLUMN_TOKENS` | `['phone', 'sex', 'age', 'social']` - the recognised opt-in tokens. |
| `enabledColumns()` (static) | The opt-in columns switched on, read from `larafoundry.admin.user_columns` and intersected with `COLUMN_TOKENS`. Non-array config returns `[]`. |
| `full($model)` (static) | A resource that always carries the personal columns, for the single-user edit context. Uses `new static` so a host subclass keeps its own type and `extra()`. |
| `$withPersonalColumns` | When true (set by `full()`), the personal columns and the raw `birth_date` are serialised regardless of the opt-in. |
| `extra($request)` (protected) | Host seam. Core returns `[]`; a subclass returns display-cell descriptors appended under `extra_columns`. |

The serialised payload always includes: `id`, `name`, `lastname`, `middlename`,
`avatar_url`, `email`, `country`, `locale`, `auth_type` (`oauth` when
`provider_name` is set, else `password`), `auth_provider`, `is_admin`,
`email_verified`, `phone_verified`, `is_blocked`, `is_deleted`, `block_code`,
`block_reason`, the company counts (`companies_count`, `owned_companies_count`,
`employee_companies_count`), `created_at`, `registered_date`,
`last_activity_at`, `last_activity_human`, and `extra_columns`. The `phone`,
`sex`, `age` and `social_links` keys appear only when their token is enabled
(gated with `when()`); the raw `birth_date` appears only in the `full()` (edit)
context. `age` is derived from `birth_date`, never the raw DOB.

### `AdminUsersFilter`

Extends the reflection-based `Filter`. Each public method (`search`,
`registered`, `emailVerified`, `status`, `recentActivity`, `locale`, `authType`,
`country`, `phoneVerified`, `sex`, `ageRange`) maps to exactly one request key
and is the only way that key touches the query. See the Usage filter table for
values and effects.

### `ImpersonationPolicy`

`take(Authenticatable $admin, Authenticatable $target): bool`. Returns true only
when: the operator is a super-admin (`VisitorStatus`); it is not self; the target
is not admin-flagged (checked independent of the email allow-list); and the
target is neither blocked nor deleted. Constructed with the `VisitorStatus`
service (container-resolved, injected into `ImpersonateController`).

### Requests

- `StoreUserRequest` - shapes create input; `authorize()` only checks the request
  is authenticated (the route already gates by super-admin). `social_links.*.url`
  runs through the `HttpUrl` rule, locking the scheme to http(s) so a stored link
  can never render a `javascript:` / `data:` href.
- `UpdateUserRequest` extends it: password nullable, email uniqueness ignores the
  edited row.

## Security notes

This module carries the fixes for the donor's operator-console holes:

- **Impersonation is gated and fully audited.** The donor left the gate
  commented out, so any admin could impersonate anyone with no record. Here a
  super-admin only (never another admin/super-admin, never self, never a
  blocked/deleted account) may `take`, and BOTH take and leave are written to the
  `admin` log. `take` is also rate-limited per operator (10 per minute, 429 when
  exceeded), the take is recorded before the login swap while the causer is still
  the admin, and the session id is rotated on each identity change (defence
  against fixation). Nesting is refused (`take` aborts 403 if already
  impersonating), which also stops an impersonated session from starting a new
  one. If the operator account has vanished on `leave`, it fails safe by logging
  out entirely.
- **Block takes hold immediately.** Blocking (and soft-delete) purge the user's
  tracked session rows, so the block is not deferred to the next page load.
  `EnsureAccountIsActive` then keeps them out on the next request.
- **Every state change is audited.** block, unblock, delete, restore, create,
  update, and both verify/unverify overrides all write to the `admin` activity
  log through one `audit()` helper (log name `admin`, a `target_id` property, the
  affected user as subject, geo enqueued so a slow provider never blocks the
  action).
- **The default payload is privacy-clean.** With `user_columns` empty, no
  personal column (`phone`/`sex`/`age`/social links) is serialised at all - the
  gating happens with `when()` at the resource level, not just in the UI. The
  opt-in tokens are sanitised against the known set, so a crafted config key
  cannot protrude an arbitrary field.
- **State columns are input-proof.** `user_blocked_at`, `block_code` and
  `user_deleted_at` are never accepted from the create/update forms; they are
  transitions driven only by the dedicated endpoints. `block_code` is clamped to
  the `1-255` range (0 / out-of-range becomes null) so a caller cannot overflow
  the `unsignedTinyInteger` column. `is_admin` is `forceFill`-ed from a validated
  boolean, never mass-assigned.
- **The resource seam cannot swap in an arbitrary class.** The controller
  validates `user_resource` with `is_a(..., AdminUserResource::class, true)` and
  falls back to the core resource otherwise.
- **Social-link writes are scoped and safe.** `syncSocialLinks` skips entirely
  when the request omits `social_links` (so an API/host caller that never touches
  them cannot wipe stored links), replaces atomically within a transaction scoped
  strictly to the user's own relation (no cross-user attach), is a no-op when the
  submitted set equals the stored set, and honours an explicit empty array as a
  deliberate clear. URLs are scheme-locked on write.
- **Verify overrides send nothing.** The forced verify/unverify actions never
  send mail or SMS; they are operator assertions, recorded in the log.

## Testing

The console user tests live in `tests/Feature/Admin/`:

- `AdminUsersTest` - the list, filters, create/edit, block/unblock,
  soft-delete/restore and the verify/unverify overrides.
- `ImpersonationTest` - the policy gate (super-admin only, never an admin, never
  self, never blocked/deleted), the take/leave audit, rate limiting and session
  rotation.
- `AdminUserResourceSeamTest` - the host resource seam: a subclass's `extra()`
  cells surface under `extra_columns`, and a config class that does not extend
  `AdminUserResource` is ignored (falls back to the core resource, no host
  cells).
- `AdminUserSocialLinksTest` - the social-links opt-in column and the
  `syncSocialLinks` guards.
- `SuperAdminConfinementTest` / `AdminOtpGateTest` - the gate and OTP step-up
  that wrap every route here.

Run them with Pest:

```bash
composer test
```

## What changed from the early draft

There is no earlier planning draft for this module under `modules/`. The changes
worth recording are relative to the donor `Admin\UserController` this module was
extracted from:

| Donor | Shipped |
|-------|---------|
| `canImpersonate()` commented out - any admin could impersonate anyone, unaudited | `ImpersonationPolicy` gate (super-admin only, never another admin, never blocked/deleted), both take and leave audited, rate-limited, session rotated (recon finding #1, HIGH) |
| Block did not clear lingering sessions | block and soft-delete purge the user's tracked sessions so the block takes hold at once (finding #4) |
| block/unblock/delete/undelete not logged | all four (plus create/update and the verify overrides) write to the `admin` activity log (finding #8) |
| Social links exposed in the user list | omitted by default; opt-in behind the `social` token, only `platform`/`url` exposed (finding #6, PII) |
| True/hard delete | reversible operator soft-delete (`user_deleted_at`); GDPR erasure is a separate flow (finding #7) |
| No forced verification | operator email/phone verify/unverify overrides (audited, no mail/SMS) |
| Personal columns always present | `phone`/`sex`/`age` opt-in via `user_columns`, default empty = privacy-clean payload |
| Fixed column set | host `extra_columns` seam via an `AdminUserResource` subclass + config, no fork of the Vue table |
