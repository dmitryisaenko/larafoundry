# Roles and Permissions (RBAC)

The authorization layer decides who may do what, tenant by tenant. Permissions are
atomic slugs in `module.action` form; roles bundle them; a company holds its own
roles and grants. Every check runs in a company context and follows one fixed
priority: super-admin, then owner, then the user's resolved permission set (roles
plus individual grants, minus individual revocations). Owners and super-admins
bypass the set entirely, so a host almost never writes a manual `where` on a
permission table.

This is the current, accurate reference for the shipped package. An older planning
draft lives at [modules/traits_middlewares.md](modules/traits_middlewares.md); it
predates the build, folds RBAC into the middleware page and uses names that changed
(see [What changed](#what-changed-from-the-early-draft)).

## Contents

- [Install](#install)
- [Configuration](#configuration)
- [Usage](#usage)
- [API reference](#api-reference)
- [Security notes](#security-notes)
- [Testing](#testing)
- [What changed from the early draft](#what-changed-from-the-early-draft)

## Install

RBAC ships with the core package; there is nothing extra to require. The host opts
in by:

1. Adding the `HasRolesAndPermissions` trait to its `User` model, alongside the
   auth and tenancy traits (the trait-slot idiom: identity, tenancy, authorization).
2. Running the migrations the package contributes (the `permissions` and `roles`
   tables, plus the pivots `role_permissions`, `user_roles` and `user_permissions`).
3. Running `php artisan larafoundry:permissions:sync` as part of install, so the
   catalog config becomes the seeded permission/role rows.

```php
// app/Models/User.php
use Dmitryisaenko\LaraFoundry\Auth\Concerns\IsLaraFoundryUser;
use Dmitryisaenko\LaraFoundry\Authorization\Concerns\HasRolesAndPermissions;
use Dmitryisaenko\LaraFoundry\Tenancy\Concerns\BelongsToTenancy;

class User extends Authenticatable
{
    use IsLaraFoundryUser, BelongsToTenancy, HasRolesAndPermissions;
}
```

The package's migrations and the default catalog config are loaded automatically.
Until `larafoundry:permissions:sync` has run there are no permission rows and the
`authenticated` role does not exist; the registration listener skips silently in
that case (a missing base role must never block sign-up), so seed the catalog
before opening registration.

## Configuration

The permission catalog is the single source of truth for which permissions, global
roles and role templates exist. It lives in its own config file,
`config/larafoundry-permissions.php`. The core ships permissions only for its own
modules (profile, companies, invitations, company management); domain permissions
(orders, warehouse, production) are host territory. Publish the file, add your
modules and templates, then re-run sync:

```bash
php artisan vendor:publish --tag=larafoundry-permissions
php artisan larafoundry:permissions:sync
```

The file has three top-level keys:

```php
return [
    'permissions' => [
        'company_management' => [
            'label' => 'Company - management',
            'permissions' => [
                'company.roles.view' => 'View company roles',
                'company.roles.create' => 'Create company roles',
                // ...
            ],
        ],
    ],
    'role_templates' => [
        'member' => [
            'name' => 'Member',
            'description' => 'Base company member',
            'permissions' => ['company.settings.view'],
        ],
    ],
    'global_roles' => [
        'authenticated' => [
            'name' => 'Authenticated User',
            'permissions' => ['profile.view', 'companies.create', /* ... */],
        ],
    ],
];
```

| Key | What it does |
|-----|--------------|
| `permissions` | Grouped by module: `'module_key' => ['label' => ..., 'permissions' => ['slug' => 'description']]`. Slugs use dot notation `module.action` and are globally unique. Every slug becomes a Gate ability at boot. |
| `role_templates` | Roles cloned into every new company on creation (`is_template`, `company_id` NULL). The core ships one neutral `member` starter; the host adds its real domain templates (manager, accountant). `'*'` in a template's permissions means "all permissions", resolved at sync time. |
| `global_roles` | Roles with `is_global`, `company_id` NULL that apply everywhere and are never cloned. The core ships only `authenticated`, auto-assigned to every registered user. |

Super-admin is deliberately NOT a role in this file. It is an identity flag
resolved by the core's `VisitorStatus` and short-circuits every check via
`Gate::before`.

The `larafoundry:permissions:sync` command is idempotent: it upserts by slug
(`updateOrCreate`), so editing a description or adding a slug and re-running is
safe and non-destructive. `--fresh` wipes permissions and roles first (and, via FK
cascade, every assignment) behind a production confirmation; `--cleanup` removes
rows no longer present in the catalog after confirmation.

Role management routes (below) load only when `larafoundry.tenancy.mode` is not
`personal` - roles are a company concept. Gates register in every mode, since
global roles work without companies.

## Usage

### Checking a permission

Every catalog slug is registered as a Gate ability with the active company
resolved for you, so call sites stay context-free. The trait, the Gate facade and
`$this->authorize()` all agree:

```php
// In a controller
$this->authorize('company.roles.create');

// Anywhere
if (Gate::allows('company.employees.remove')) { /* ... */ }

// Directly on the user, with an explicit company if needed
$user->hasPermissionTo('company.settings.update', $company);
$user->hasAnyPermission(['company.roles.update', 'company.roles.delete'], $company);
$user->hasPermissionPattern('company.roles.*', $company);
```

Passing `null` as the company checks the global scope only. A super-admin passes
everything; an owner passes everything in their own company; everyone else is
tested against their resolved slug set.

### Assigning roles and permissions

The trait mutates a user's roles and individual grants. All methods take an
optional company (defaults to the global scope) and an optional actor id recorded
on the pivot:

```php
$user->assignRole('member', $company, $actorId);
$user->syncRoles([$roleA, $roleB], $company, $actorId);   // replaces the set
$user->removeRole('member', $company);

$user->givePermissionTo('company.roles.view', $company, $actorId);   // explicit grant
$user->revokePermissionFrom('company.roles.delete', $company, $actorId); // explicit revoke
$user->removePermission('company.roles.view', $company);  // drops the override row
```

An individual grant adds a permission on top of what the user's roles give; an
individual revoke removes one even if a role would otherwise grant it. Revoke beats
grant beats role. The resolved set is memoized per company for the request and
reset whenever an assignment mutates.

### Default roles on company creation

When a company is created, `CreateCompanyAction` dispatches `CompanyCreated`. The
`CloneCompanyRoles` listener catches it and queues `CloneCompanyRolesJob`, which
clones the catalog's role templates into the new company (via
`CloneCompanyRolesAction`). Cloning is queued because the owner already has full
access through the ownership bypass, so the templates only matter for employees
invited later. The same action also runs synchronously from the wizard when the
role-at-invite dropdown needs the roles immediately - it is idempotent and
lock-guarded, so both paths are safe.

When a member is removed, `EmployeeRemoved` fires and
`RevokeAccessOnEmployeeRemoval` detaches that member's role assignments and
individual overrides scoped to that company. Global roles (like `authenticated`)
are left untouched.

### Managing roles in the company UI

In teams mode the package registers role CRUD and per-member access routes under
the `authorization.*` name prefix. The host links to them; it does not define them.
All are behind `web, auth, verified, larafoundry.tenant.set,
larafoundry.tenant.required`.

| Method + URI | Route name | Purpose |
|--------------|-----------|---------|
| `GET /roles` | `authorization.roles.index` | List the active company's roles |
| `GET /roles/create` | `authorization.roles.create` | New-role form |
| `POST /roles` | `authorization.roles.store` | Create a custom role |
| `GET /roles/{role}/edit` | `authorization.roles.edit` | Edit form |
| `PUT /roles/{role}` | `authorization.roles.update` | Update a custom/company role |
| `DELETE /roles/{role}` | `authorization.roles.destroy` | Delete a custom role |
| `PUT /employees/{user}/roles` | `authorization.employees.roles` | Replace a member's roles |
| `PUT /employees/{user}/permissions` | `authorization.employees.permissions` | Grant/revoke a member's individual permissions |

### Sharing permissions with the frontend

Merge the authorization shared props into the host's
`HandleInertiaRequests::share()`, in parallel with the tenancy helper. This exposes
a flat `permissions` list (the user's effective slugs in their active company) that
any page can membership-test to drive permission-aware UI:

```php
use Dmitryisaenko\LaraFoundry\Authorization\LaraFoundryAuthorization;

public function share(Request $request): array
{
    return [
        ...parent::share($request),
        ...LaraFoundryAuthorization::sharedProps(),
    ];
}
```

The prop is lazily evaluated and reads from the request-memoized set, so sharing it
adds no queries once a page has already checked a permission. It is empty for
guests and users without the trait.

## API reference

### `HasRolesAndPermissions` (on the `User` model)

Relations and checks:

- `roles()`, `permissions()` - the `user_roles` / `user_permissions` pivots, both
  carrying `company_id`.
- `hasPermissionTo($permission, $company = null)`, `hasAnyPermission()`,
  `hasAllPermissions()`, `hasPermissionPattern($pattern, $company = null)`.
- `hasRole()`, `hasAnyRole()`, `hasAllRoles()`.
- `isSuperAdmin()` (delegates to `VisitorStatus`),
  `hasOnlyAuthenticatedRole()`.
- `getAllPermissions($company = null)` - the effective slug Collection (owners and
  super-admins get the whole catalog); `getRolesInCompany()`, `getGlobalRoles()`.

Mutations (each returns `$this`, resets the memoized set):

- `assignRole()`, `removeRole()`, `syncRoles()`.
- `givePermissionTo()`, `revokePermissionFrom()`, `removePermission()`.

### `Role` model

`company()` (points at the configured company model), `createdBy()`,
`permissions()`, `users()`. Scopes `global()`, `template()`, `custom()`,
`forCompany($companyId)`. Predicates `isGlobal()`, `isTemplate()`, `isCustom()`,
`isEditable()` (neither global nor template), `canBeDeleted()` (custom AND no
holders). Permission helpers `hasPermission()`, `givePermissionTo()`,
`revokePermissionFrom()`, `syncPermissions()`. Four kinds are distinguished by
flags, not a `level` column: global, template, company (a template clone) and
custom.

### `Permission` model

`roles()`, `users()`. Scopes `forModule($module)`, `byPattern($pattern)` (SQL
`LIKE` on the slug). `findBySlug($slug)`, `matches($pattern)` (regex-escaped, so a
literal dot in a slug can never act as a wildcard). Permissions are seeded from the
catalog and never created at runtime.

### `LaraFoundryAuthorization` (host wiring helper)

| Method | Returns | Purpose |
|--------|---------|---------|
| `sharedProps()` | `array<string, Closure>` | Inertia prop: `permissions` (flat slug list). |

### `PermissionCatalog` (read-only accessor over the config)

`modules()`, `slugs()`, `permissions()` (flat `slug => [module, description]`),
`roleTemplates()`, `globalRoles()`. The single read-point the sync command, the
gate registrar, the controllers and the form requests all share.

### Gates

- `PermissionGateRegistrar::register()` - a `Gate::before` short-circuits
  super-admins for every ability, then one `Gate::define` per catalog slug (so the
  ability list stays introspectable).
- `RoleGates::register()` - the role-management abilities `roles.viewAny`,
  `roles.create`, `roles.update`, `roles.delete`, `roles.managePermissions`. These
  layer structural rules (the role must belong to the active company, must be
  editable, must be deletable) on top of the underlying `company.roles.*`
  permission.

### Console command

`larafoundry:permissions:sync {--fresh} {--cleanup} {--force}` - sync the catalog
config into the database (see [Configuration](#configuration)).

### Events (extension hooks)

`RoleCreated`, `RoleUpdated`, `RoleDeleted` (each carries the `$role`), under
`Dmitryisaenko\LaraFoundry\Authorization\Events\`. Dispatched by `RoleController`.

### Listeners

- `AssignAuthenticatedRole` on `Registered` - gives every new user the global
  `authenticated` role (native and OAuth sign-ups both); idempotent, skips if the
  role is unseeded.
- `CloneCompanyRoles` on `CompanyCreated` - queues `CloneCompanyRolesJob`.
- `RevokeAccessOnEmployeeRemoval` on `EmployeeRemoved` - strips the removed
  member's company-scoped roles and overrides.

## Security notes

RBAC is built so that a delegated member can never manufacture authority they were
not given. The guarantees worth knowing:

- **The check priority is fixed and revoke-beats-grant.** `hasPermissionTo()`
  resolves super-admin, then owner, then `(company roles ∪ global roles ∪ grants) −
  revocations`. Because the `(user, permission, company)` pivot is unique, a
  permission is either granted or revoked, so an explicit revoke always wins over a
  role grant.
- **No privilege escalation beyond your own authority.** When a member assigns
  roles or grants individual permissions, `EmployeeAccessController` aborts 403 if
  the target role or slug carries any permission the actor does not hold. Owners
  and super-admins hold the whole catalog (the bypass), so they are unaffected; a
  delegated member with `assign_role` or `grant_permissions` cannot smuggle in a
  power like `company.roles.delete` they were never given. Revoking is a downgrade
  and needs no such check.
- **Roles resolve through the active company (anti-IDOR).** `RoleController`
  fetches every `{role}` with `where('company_id', activeCompany)->findOrFail()`,
  so a role belonging to another tenant is simply a 404. The `roles.update` /
  `roles.delete` gates repeat the same active-company check structurally, so a
  forgotten guard cannot leak cross-tenant access.
- **The server sets role flags, never the request.** On create, `is_custom`,
  `is_global`, `is_template`, `company_id` and `created_by_id` are set by the
  controller. `StoreRoleRequest` only shapes the name, description and permissions;
  a member cannot forge a global or cross-company role by posting flags.
- **Only known slugs can be attached.** Both the role form request and the
  per-member permission update constrain permissions with `Rule::in($catalog
  ->slugs())`, so an attacker cannot attach an arbitrary slug that some future gate
  might honour.
- **Owners are untouchable through the member path.** `EmployeeAccessController`
  aborts 403 when the target member is an owner (the `is_owner` pivot flag).
  Ownership comes from tenancy, not roles, and cannot be stripped or overridden by
  assigning roles.
- **In-use roles cannot be silently deleted.** `canBeDeleted()` returns false while
  any member of the company still holds the role, so deleting one can never quietly
  drop someone's access. Editing is limited to company and custom roles;
  global/template rows are managed only through the catalog config and `sync`.
- **Removing a member strips their access.** `RevokeAccessOnEmployeeRemoval`
  detaches company-scoped roles and overrides, so a re-resolved former member
  carries no lingering authority, and a role held only by removed members becomes
  deletable again.

## Testing

The authorization suite lives in `tests/Feature/Authorization/` and uses
`RefreshDatabase`. Notable files:

- `GateTest`: the check priority (super-admin, owner, grant, revoke), the per-slug
  gates and the global vs company scope.
- `RoleManagementTest`: role CRUD through the controller, the anti-IDOR 404, the
  server-set flags and the deletable/editable structural rules.
- `EmployeeAccessTest`: assigning roles and grants to a member, the
  privilege-escalation 403, the owner-untouchable guard and slug validation.
- `LifecycleTest`: `AssignAuthenticatedRole` on registration, `CloneCompanyRoles`
  on company creation and `RevokeAccessOnEmployeeRemoval` on removal.
- `CloneCompanyRolesActionTest`: the idempotent, lock-guarded template clone across
  the async and synchronous paths.

Run them with Pest:

```bash
composer test
```

## What changed from the early draft

If you arrived from the older
[modules/traits_middlewares.md](modules/traits_middlewares.md), which folded RBAC
into the middleware page, these names and facts changed on the way to the shipped
package:

| Early draft | Shipped |
|-------------|---------|
| RBAC described as one line under the middleware/traits page | Roles and permissions ship as their own phase (1.3), namespaced `Dmitryisaenko\LaraFoundry\Authorization\` |
| `BelongsToCompany` trait in the stack diagram | `BelongsToTenant` (domain models) / `BelongsToTenancy` (user); RBAC is the separate `HasRolesAndPermissions` trait |
| Spatie ActivityLog referenced nearby | RBAC has no Spatie dependency; roles/permissions/pivots are the package's own tables |
| Role hierarchy implied by a level | No `level` column - the hierarchy is the trait's fixed check priority; role kinds are distinguished by the `is_global` / `is_template` / `is_custom` flags |
| Super-admin as a role | Super-admin is a `VisitorStatus` identity flag, never a role; it short-circuits via `Gate::before` |
