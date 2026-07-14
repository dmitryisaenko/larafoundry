# Admin: Companies

The operator console screen for managing tenant companies. A super-admin can list,
search and inspect every company on the platform, block or unblock one, and read a
company's subscription state. It sits inside the same admin zone as user management
(phase 2.3) and the dashboard (phase 3.4), behind the `larafoundry.admin` gate.

This is the current, accurate reference for the shipped package. An older planning
draft lives at [modules/admin_companies.md](modules/admin_companies.md); it predates
the build and describes a different scope (see
[What changed](#what-changed-from-the-early-draft)). The tenant-facing side of company lifecycle (an owner archiving their own
company, the block cascade at the tenancy boundary) is documented in
[multi-tenancy.md](multi-tenancy.md); this page covers only the operator console and
the host-facing archive events.

## Contents

- [Install](#install)
- [Configuration](#configuration)
- [Usage](#usage)
- [API reference](#api-reference)
- [Security notes](#security-notes)
- [Testing](#testing)
- [What changed from the early draft](#what-changed-from-the-early-draft)

## Install

The admin companies console ships with the core package; there is nothing extra to
require. It depends on tenancy (the `Company` model and its block/subscription
columns) and on the activity log (block/unblock actions are audited). A host gets the
screen for free once it:

1. Has tenancy wired in `teams` mode (see [multi-tenancy.md](multi-tenancy.md)) so a
   `Company` model exists.
2. Runs the package migrations, which contribute the block columns
   (`company_blocked_at`, `company_blocked_reason`) and the free-core billing columns
   (`plan_id`, `billing_period`, `trial_ends_at`, `subscription_ends_at`) on the
   companies table.
3. Exposes at least one super-admin (a user whose `VisitorStatus::isAdmin()` returns
   true), because every route here is behind the `larafoundry.admin` gate.

The admin routes (`routes/admin.php`) are registered automatically by
`LaraFoundryServiceProvider`. The console list appears in the admin navigation via
`AdminMenuProvider` (route `admin.companies.index`, active on `admin.companies.*`).

## Configuration

The console reads three keys under the `admin` block of `config/larafoundry.php`:

```php
'admin' => [
    'companies_per_page' => 21,
    'subscription_expiring_within_days' => 7,
    // ... user-console keys ...
],
```

| Key | Default | What it does |
|-----|---------|--------------|
| `admin.companies_per_page` | `21` | Page size of the company list. |
| `admin.subscription_expiring_within_days` | `7` | An active subscription whose end date falls within this many days is classified as `expiring` in the list. Display-only: access is still granted until the end date passes (`Company::hasAccess()`). The status badge and the filter read the same value, so they can never disagree. |

Which model the console operates on is resolved from
`larafoundry.tenancy.company_model`, so a host that subclasses `Company` gets its own
model listed and blocked without any console change.

## Usage

### The routes

All under the `admin.` name prefix, behind `web, auth, verified, larafoundry.admin`
and the OTP step-up gate (`larafoundry.admin.otp`):

| Method | URI | Name | Purpose |
|--------|-----|------|---------|
| GET | `/admin/companies` | `admin.companies.index` | Paginated, filtered list (Inertia). |
| GET | `/admin/companies/search` | `admin.companies.search` | Type-ahead search (JSON, capped at 20 rows). |
| GET | `/admin/companies/{company}` | `admin.companies.show` | Read-only detail for one company. |
| POST | `/admin/companies/{company}/block` | `admin.companies.block` | Block a company (optional `reason`). |
| POST | `/admin/companies/{company}/unblock` | `admin.companies.unblock` | Lift a block. |

Companies are route-bound by their `uuid`, never the numeric id
(`Company::getRouteKeyName()`).

### Filtering the list

The list (and the `search` endpoint) accept these query parameters, each handled by
one method on `AdminCompaniesFilter`:

- `search` - free text across company name/slug and the owner's name, lastname and email.
- `country` - exact ISO country code.
- `dateFrom` / `dateTo` - creation-date window (`Y-m-d`); unparseable values are ignored.
- `subscriptionStatus` - one of `on_trial`, `active`, `expiring`, `expired`,
  `never_activated`. The classification is expressed as SQL so it pages in the
  database, and it mirrors `SubscriptionStatus` exactly.
- `blockState` - `blocked` or `active`.

Empty parameters are skipped by the base `Filter` class. Only named methods can touch
the query, so a crafted parameter (including an array-shaped one) cannot invoke an
unintended path.

### Blocking a company

Blocking sets `company_blocked_at` (and an optional `company_blocked_reason`) via
`forceFill`, purges the tracked-session rows pointing at that company, and writes an
`admin.company.blocked` entry to the activity log. Enforcement of the block does not
live in this controller; it lives at the single tenancy boundary
(`EnsureActiveTenant`), which denies the tenant screens to every member whose active
company is blocked, on their next request.

The session purge is not a logout. It deletes only the `user_sessions` rows whose
`active_company_id` equals this company, so those devices stop resolving to it and the
tenancy middleware self-heals them onto another available company right away, instead
of the block only biting on the member's next manual company switch. A member of two
companies keeps the other one - rows scoped to other companies are untouched.

Unblocking clears both columns and logs `admin.company.unblocked`. Sessions are not
restored: members simply switch back to the company on their next visit.

### The block cascade and self-heal (enforced in tenancy)

When a member's active company is blocked, `EnsureActiveTenant` first tries
`setNextAvailableCompany()`, which promotes one of their other, un-blocked (and
un-archived) companies and replays the request. Only if they have no usable company
left is the active company cleared and the member redirected - to
`larafoundry.auth.blocked_redirect_route` if the host defined one, otherwise the
create-company flow. The member is never logged out (they may own healthy companies)
and the redirect cannot loop, because with no active company the boundary sees
"no active company" rather than re-detecting the block. The full mechanism is in
[multi-tenancy.md](multi-tenancy.md).

### Reading subscription state

The console shows subscription state read-only, computed from the free-core billing
columns. Every company in the list and detail carries a `subscription` block:

```json
"subscription": {
  "plan_id": null,
  "billing_period": null,
  "trial_ends_at": null,
  "subscription_ends_at": null,
  "status": "never_activated",
  "has_access": true
}
```

`status` is one of `on_trial`, `active`, `expiring`, `expired`, `never_activated`
(see `SubscriptionStatus`). With billing disabled (the FREE default) every company
reads as `never_activated` with `has_access: true`: that is honest, not a bug - the
screen shows that billing is not wired here, and `has_access` reflects the real access
gate (`Company::hasAccess()`).

The console never offers to change a subscription. Managing a plan or period -
starting a trial, extending, cancelling - is the job of the paid billing add-on and is
out of scope for the core. The console also never shows per-company payment sums or
last-payment rows: those records live in the add-on, so the core does not display
money it does not store.

### Company-archive events for the host

Archiving a company is owner-driven, not an operator action, and is described in
[multi-tenancy.md](multi-tenancy.md). What the operator side (and any host) can react
to are the two events raised when an owner archives or restores their company
(phase 7 / v0.27):

- `CompanyArchived` - fired when an owner archives their company.
- `CompanyUnarchived` - fired when an owner restores it.

Both live under `Dmitryisaenko\LaraFoundry\Tenancy\Events\`, carry only the `Company`
(the acting user is the causer, resolved by the activity-log listener), and expose
`getLogProperties()` returning `company_id` and `company_uuid`. They are registered in
the activity-log event registry (`config/larafoundry-activitylog.php`, group
`Tenancy`), so archiving and unarchiving are audited out of the box. A host can add its
own listener to either event for side effects of its own.

## API reference

### `CompanyController` (`Admin\Http\Controllers`)

| Method | Signature | Returns |
|--------|-----------|---------|
| `index` | `index(Request $request)` | `Inertia\Response` (`Admin/Companies/Index`) |
| `show` | `show(string $company)` | `Inertia\Response` (`Admin/Companies/Show`) |
| `search` | `search(Request $request)` | `JsonResponse` |
| `block` | `block(Request $request, string $company)` | `RedirectResponse` |
| `unblock` | `unblock(Request $request, string $company)` | `RedirectResponse` |

`block`/`unblock` authorise via `CompanyPolicy::block()` and abort 403 when it fails.

### `CompanyPolicy` (`Admin\Policies`)

A plain injected class (not a Gate-registered model policy), mirroring
`ImpersonationPolicy`, because block/unblock is an operation, not an ability on a
single model instance.

| Method | Signature | Purpose |
|--------|-----------|---------|
| `block` | `block(Authenticatable $admin): bool` | Whether the actor may block or unblock, via `VisitorStatus::isAdmin()`. |

### `AdminCompaniesFilter` (`Admin\Http\Filters`)

Extends the reflection-based `Filter`. One public method per request key:
`search`, `country`, `dateFrom`, `dateTo`, `subscriptionStatus`, `blockState`.

### `AdminCompanyResource` (`Admin\Http\Resources`)

Serialises one company for the console:
`uuid`, `name`, `slug`, `country`, `logo_url`, `owner` (`id`, `name`, `lastname`,
`email` - identity and contact only), `employees_count`, `is_blocked`,
`blocked_reason`, `subscription` (`plan_id`, `billing_period`, `trial_ends_at`,
`subscription_ends_at`, `status`, `has_access`), `created_at`, `created_date`.

### `SubscriptionStatus` (`Billing\Support`)

The single place the display vocabulary is computed, shared by the filter and the
resource. Read-only.

| Member | Value | Meaning |
|--------|-------|---------|
| `ON_TRIAL` | `on_trial` | Live trial (`trial_ends_at` in the future). |
| `ACTIVE` | `active` | Active subscription, end date beyond the expiring window. |
| `EXPIRING` | `expiring` | Active subscription whose end date is within `subscription_expiring_within_days`. |
| `EXPIRED` | `expired` | A trial or subscription end date existed and is now past. |
| `NEVER_ACTIVATED` | `never_activated` | No billing date ever set. |
| `for($billable)` | `string` | Classify a billable model (delegates the future-date check to `SubscriptionState`). |

### Company block API (`Tenancy\Models\Company`)

`isBlocked(): bool` returns whether `company_blocked_at` is set. The block columns are
not in `$fillable`; the console writes them with `forceFill`. Enforcement is
`EnsureActiveTenant` (alias `larafoundry.tenant.required`).

### Archive events (`Tenancy\Events`)

`CompanyArchived`, `CompanyUnarchived` - each `readonly Company $company` plus
`getLogProperties(): array` (`company_id`, `company_uuid`).

## Security notes

- **Blocking is double-locked.** The whole admin zone is already behind the
  `larafoundry.admin` route gate, but the destructive block/unblock action is checked
  again at the action through `CompanyPolicy::block()` (canonical `VisitorStatus`
  super-admin check), so authority is verified where the state changes, not only at
  the route.
- **The block column is not mass-assignable.** `company_blocked_at` and
  `company_blocked_reason` are absent from `Company::$fillable` and are written only by
  the console via `forceFill`. A caller cannot pre-set or pre-clear them through
  ordinary model fill, which a dedicated test asserts.
- **Enforcement is at one boundary, not scattered.** A blocked company denies its
  tenant screens to every member at `EnsureActiveTenant`, regardless of role, so there
  is no screen a blocked company's member can reach. One column takes the whole team
  offline.
- **The purge is not a logout, and it is scoped.** Blocking deletes only the tracked
  `user_sessions` rows whose `active_company_id` is this company; auth sessions and
  rows for other companies are untouched. A multi-company member keeps their other
  companies.
- **Companies resolve by uuid, never numeric id.** Route binding uses `uuid`, so
  sequential ids are not exposed in operator URLs.
- **Filters cannot invoke arbitrary query paths.** The reflection-based `Filter` maps
  each request key to one named method and skips empty or array-shaped values, so a
  crafted parameter cannot reach an unintended query branch.
- **The console never surfaces money it does not store.** Payment sums and last-payment
  rows (present in the donor's admin list) are deliberately absent; payment records
  live in the paid add-on. The owner is summarised to identity and contact only (no
  social profiling).
- **Subscription state is read-only.** The console reports state but never offers to
  change it - changing a plan or period is the add-on's job.

## Testing

The console suite is `tests/Feature/Admin/AdminCompaniesTest.php`, using
`RefreshDatabase`. Covered behaviour:

- Access control: a non-admin is forbidden and a guest is redirected from the list;
  a super-admin can list.
- Listing: employee counts include only non-removed members; pagination honours the
  configured page size; search matches company name and owner email (including the
  JSON `search` endpoint).
- Subscription: status is reported read-only from the billing columns, and reads
  `never_activated` with `has_access: true` when billing is disabled; the resource
  never exposes payment sums.
- Block/unblock: blocking a company sets the columns, purges its sessions and logs
  `admin.company.blocked`; sessions scoped to other companies are not purged;
  unblocking clears the columns and logs `admin.company.unblocked`; a non-admin is
  forbidden from blocking; `company_blocked_at` cannot be mass-assigned.
- Filters: filtering by block state, and an array-shaped filter param is ignored
  rather than crashing.

`tests/Feature/Admin/CompanyBlockMigrationTest.php` covers the block-column migration.

Run the suite with Pest:

```bash
composer test
```

## What changed from the early draft

There was no separate early-draft module document for the admin companies screen, so
there are no renamed names to reconcile here. The one design note worth recording is
the deliberate narrowing from the donor's admin company list (recon finding E): the
donor screen was read-only (index plus search, no way to act on a company) and carried
per-company payment sums and last-payment rows. The shipped console keeps the
read-only list and detail, ADDS the company-level block/unblock the donor lacked, and
drops the payment/revenue columns and facets - those records belong to the paid billing
add-on, so the FREE core neither stores nor displays them.
