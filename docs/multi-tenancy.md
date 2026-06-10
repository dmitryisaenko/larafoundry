# Multi-Tenancy

The tenancy layer isolates every host's data by tenant. In the default `teams`
mode the tenant is a `Company` and a user can belong to several; in `personal`
mode the tenant is the user itself and there are no companies. The same trait,
scope and middleware serve both modes, so a host picks one in config and writes no
isolation logic of its own.

This is the current, accurate reference for the shipped package. An older planning
draft lives at [modules/multi_tenancy.md](modules/multi_tenancy.md); it predates
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

Tenancy ships with the core package; there is nothing extra to require. The host
opts in by:

1. Adding the `BelongsToTenancy` trait to its `User` model.
2. Pointing `larafoundry.tenancy.company_model` at its own `Company` subclass.
3. Running the migrations the package contributes (the `companies` table, the
   `company_user` pivot, `company_invitations`, and an `active_company_id` column
   on `user_sessions`).

```php
// app/Models/User.php
use Dmitryisaenko\LaraFoundry\Tenancy\Concerns\BelongsToTenancy;

class User extends Authenticatable
{
    use BelongsToTenancy;
}
```

```php
// app/Models/Company.php (the host owns its Company, extending the core model).
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company as FoundryCompany;

class Company extends FoundryCompany
{
    // Add host-specific columns/relations here. Keep it a subclass so the
    // package keeps resolving the configured model everywhere.
}
```

The package's migrations are loaded automatically. The base `companies` table is
deliberately minimal (decision D1.2-a): only the columns every multi-tenant SaaS
needs. Business-specific columns belong to a host-owned migration on top.

## Configuration

All tenancy settings live under the `tenancy` key of `config/larafoundry.php`:

```php
'tenancy' => [
    'mode' => env('LARAFOUNDRY_TENANCY_MODE', 'teams'),
    'company_model' => App\Models\Company::class,
    'foreign_key' => 'company_id',
    'invitation_expiry_days' => 7,
    'routes_without_active_tenant' => [
        'tenancy.employees.request-removal',
        'tenancy.employees.cancel-removal',
    ],
    'home_route' => env('LARAFOUNDRY_TENANCY_HOME', '/'),
],
```

| Key | Default | What it does |
|-----|---------|--------------|
| `mode` | `teams` | `teams` makes `Company` the tenant and registers the company wizard, invitations and switcher. `personal` makes the `User` the tenant and registers none of the company flow; `BelongsToTenant` then scopes by `user_id`. |
| `company_model` | core `Company` | The model the package resolves everywhere. A host points this at its own subclass. |
| `foreign_key` | `company_id` | The tenant foreign key on domain models, in `teams` mode. `personal` mode always scopes by `user_id` regardless of this value. |
| `invitation_expiry_days` | `7` | How long an employee invitation stays actionable. |
| `routes_without_active_tenant` | two employee routes | Route names (matched as wildcards with Laravel's `Str::is()`) a company user may reach without an active company selected, for example to request their own removal. Read by `EnsureActiveTenant`. |
| `home_route` | `/` | Where to land after the setup wizard or an accepted invite. A route name or a raw path; the core does not assume a host route exists. |

## Usage

### Creating a company

`CreateCompanyAction` creates the company, attaches the caller as owner, sets it
active and dispatches `CompanyCreated` (the hook the authorization layer listens
to). It runs in a transaction and generates a globally unique slug.

```php
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\CreateCompanyAction;

$company = app(CreateCompanyAction::class)->execute($owner, [
    'name' => 'Acme Co',
    'country' => 'GB',          // nullable; null marks a company still in setup
    'description' => null,       // optional
]);
```

### The active company is per device

Each device (session) remembers its own active company, tracked on the
`user_sessions` row via `active_company_id`. The host rarely calls the resolver
directly; the `BelongsToTenancy` trait exposes everything a controller needs:

```php
$user->getActiveCompany();          // ?Company
$user->setActiveCompany($company);  // accepts Company|int|null
$user->clearActiveCompany();
$user->setNextAvailableCompany();   // bool: auto-pick an owned, then a member company
$user->isOwnerOf($company);         // bool
```

### Scoping domain models

Add `BelongsToTenant` to any model that belongs to a tenant. Its global scope
filters every query to the active tenant, and the tenant key is filled in on
create. You never write `where('company_id', ...)` by hand.

```php
use Dmitryisaenko\LaraFoundry\Tenancy\Concerns\BelongsToTenant;

class Order extends Model
{
    use BelongsToTenant;
}

Order::all();              // only the active tenant's orders
Order::create([...]);      // company_id filled from the active tenant
Order::forTenant(5)->get();// explicit tenant, ignoring the active one
Order::withoutTenancy()->get(); // full escape hatch for trusted admin/console code
```

### Sharing tenancy state with the frontend

Merge the package's shared Inertia props into the host's
`HandleInertiaRequests::share()`. They are lazily evaluated, so guests and
personal mode pay nothing.

```php
use Dmitryisaenko\LaraFoundry\Tenancy\LaraFoundryTenancy;

public function share(Request $request): array
{
    return [
        ...parent::share($request),
        ...LaraFoundryTenancy::sharedProps(),
    ];
}
```

This exposes `activeCompany` (`{id, uuid, name, logo_url}` or `null`) and
`companies` (the switcher list, each with `is_owner`) to every page.

### Routes the host gets in `teams` mode

The package registers the wizard, invitations, the company switcher and employee
management. The middleware stack is `web, auth, verified, larafoundry.tenant.set`,
plus `larafoundry.tenant.required` on the employee routes that need an active
company. The host does not define these routes; it links to them.

## API reference

### `LaraFoundryTenancy` (host wiring helper)

| Method | Returns | Purpose |
|--------|---------|---------|
| `sharedProps()` | `array<string, Closure>` | Inertia props: `activeCompany` and `companies`. |
| `render($page, $props = [])` | `Inertia\Response` | Render a tenancy page (parity with the auth helper). |
| `homeUrl()` | `string` | Resolve `home_route` to a URL (route name or raw path). |

### `BelongsToTenancy` (on the `User` model)

`companies()`, `ownedCompanies()`, `employeeCompanies()` (relations),
`getActiveCompany()`, `setActiveCompany()`, `clearActiveCompany()`,
`setNextAvailableCompany()`, `getCurrentCompanyId()`, `getCurrentCompanyName()`,
`isOwnerOf()`, `isOwnerOfActiveCompany()`, `getDefaultRouteForActiveCompany()`,
`setDefaultRouteForActiveCompany()`.

### `BelongsToTenant` (on domain models)

`tenant()` (the `BelongsTo` to the configured company model), and the query
scopes `forTenant($key)` and `withoutTenancy()`. The global `TenantScope` is
applied automatically.

### `Company` model

`users()` (active members), `owners()`, `createdBy()`, `invitations()`;
`addEmployee($user, $addedById, $isOwner)`, `removeEmployee($user)`;
`isInSetup()`, `isBlocked()`, `hasAccess()`. Implements the `Tenant` contract
(`getTenantKey()`).

### Actions

| Action | Signature |
|--------|-----------|
| `CreateCompanyAction` | `execute(Authenticatable $owner, array $data): Company` |
| `InviteEmployeesAction` | `execute(Company $company, array $invites, ?int $invitedBy = null): Collection` (each invite is `['email' => string, 'role_id' => ?int]`; `role_id` is the optional company-scoped role-at-invite) |
| `RemoveEmployeeAction` | `execute(Company $company, Authenticatable $employee): void` |

### Events (extension hooks)

| Event | Fired when | Carries |
|-------|-----------|---------|
| `CompanyCreated` | a company is created | `$company`, `$owner` |
| `CompanyInvitationSent` | an invitation is sent (per email) | `$invitation` |
| `EmployeeRemoved` | an employee is removed | `$company`, `$employee` |

All under `Dmitryisaenko\LaraFoundry\Tenancy\Events\`.

### Middleware aliases

- `larafoundry.tenant.set` (`SetActiveTenant`): auto-selects an available company.
- `larafoundry.tenant.required` (`EnsureActiveTenant`): gates routes that need an
  active, unblocked company.

## Security notes

Tenancy is built fail-closed. The guarantees worth knowing:

- **No tenant means no rows, never all rows.** When there is no authenticated user
  or no resolvable tenant, `TenantScope` applies `where 0 = 1` (an empty result)
  rather than skipping the filter. A missing tenant can never leak another
  tenant's data.
- **The tenant key is not mass-assignable.** `company_id` (or `user_id` in
  personal mode) is not in `$fillable`. On create the scope fills it from the
  active tenant; if a value is passed explicitly it is respected (for trusted
  admin code); if there is no active tenant the create throws rather than writing
  an orphan row.
- **Controllers resolve the company from auth, not from the request.** The
  `ResolvesActiveCompany` concern reads `getActiveCompany()` and aborts 403 if
  there is none (or if ownership is required and the user is not an owner). A
  company id or uuid from the request never selects the tenant, which closes the
  obvious IDOR.
- **Privileges do not resurrect.** Removing an employee clears their `is_owner`
  pivot flag, and re-adding as a member cannot restore ownership. Re-adding an
  existing owner keeps ownership (it never silently demotes).
- **Invitations are unguessable and hard-expiring.** Tokens are 64 random
  characters, `expires_at` is `NOT NULL` (a null is treated as expired), and
  accepting also verifies the logged-in user's email matches the invite.
- **A blocked company cascades to every member.** Setting `company_blocked_at`
  (super-admin only, not fillable) takes the whole company offline at one
  boundary (`EnsureActiveTenant`), which self-heals a multi-company user onto
  another available company.

## Testing

The tenancy suite lives in `tests/Feature/Tenancy/` and uses `RefreshDatabase`
with two fixtures: a test `User` (`use BelongsToTenancy`) and a `Note` domain
model (`use BelongsToTenant`). Notable files:

- `BelongsToTenantTest`: scope fail-closed behaviour, auto-fill on create,
  mass-assignment protection, `forTenant()` / `withoutTenancy()`.
- `BelongsToTenancyTest`: the membership relations, ownership checks, and the
  privilege-resurrection guards.
- `PersonalModeTest`: the same trait scoping by `user_id` in personal mode.
- `CompanyCreationTest`: `CreateCompanyAction`, the `CompanyCreated` event, slug
  uniqueness, and the wizard IDOR check.

Run them with Pest:

```bash
composer test
```

## What changed from the early draft

If you arrived from the older [modules/multi_tenancy.md](modules/multi_tenancy.md),
these names changed on the way to the shipped package:

| Early draft | Shipped |
|-------------|---------|
| `BelongsToCompany` trait | `BelongsToTenant` (on domain models); `BelongsToTenancy` (on the user) |
| `forCompany($id)` scope | `forTenant($id)` |
| `forAdmin()` scope | `withoutTenancy()` |
| Authorization folded into this module | Roles and permissions ship as a separate phase; a `Company` has no `roles()` relation here |
| Nullable invitation expiry | `expires_at` is `NOT NULL`, and a null is treated as expired |
