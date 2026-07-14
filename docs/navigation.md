# Navigation and operator console

The navigation layer builds the app menu on the backend, filters it against the
current user's permissions, sorts it, and ships only the survivors to Inertia. A
link the user may not follow never reaches the browser. The same request also
decides which page shell to render (the tenant app, the super-admin operator
console, or a bare base shell) from one signal, so the menu and the shell can
never disagree. A host grows the menu by registering its own provider class; it
edits nothing in the core.

This is the current, accurate reference for the shipped package. An older planning
draft lives at [modules/navigation.md](modules/navigation.md); it predates the
build and describes a different design (see
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

Navigation ships with the core package; there is nothing extra to require. The
`MenuBuilder` is registered as a singleton and already carries the RBAC policy
checker and the core's two menu providers (admin console + tenant sidebar). A host
opts in by:

1. Merging the navigation shared props into its `HandleInertiaRequests::share()`
   (below), so every page receives the filtered menu and the shell signal.
2. Using `LayoutSwitcher` as the persistent Inertia layout, so the correct shell
   is chosen per request.
3. Optionally registering one or more `MenuProviderInterface` classes to add its
   own screens to the menu.

```php
// app/Http/Middleware/HandleInertiaRequests.php
use Dmitryisaenko\LaraFoundry\Navigation\LaraFoundryNavigation;

public function share(Request $request): array
{
    return [
        ...parent::share($request),
        ...LaraFoundryNavigation::sharedProps(),
    ];
}
```

The props are lazily evaluated (each is a closure that runs only when a page
serialises it), so a guest or a page that ignores navigation pays nothing.

## Configuration

Navigation has no config block of its own; the menu is defined by provider
classes in PHP (decision D-nav-b), not by a config array, so items can be
conditional, badged or computed. The related operator-console settings live under
the `admin` key of `config/larafoundry.php` and drive the console screens the
menu points at:

```php
'admin' => [
    'users_per_page' => 21,
    'companies_per_page' => 21,
    'subscription_expiring_within_days' => 7,
    'active_within_days' => 30,
    'dashboard_activity_limit' => 10,
    'user_resource' => App\Http\Resources\AdminUserResource::class,
    'user_columns' => [],
],
```

| Key | Default | What it does |
|-----|---------|--------------|
| `users_per_page` | `21` | Page size for the admin user list. |
| `companies_per_page` | `21` | Page size for the admin company list (phase 3.3). |
| `subscription_expiring_within_days` | `7` | A still-active subscription ending inside this window is badged "expiring" in the company list. Display only. |
| `active_within_days` | `30` | A user with tracked activity inside this window counts as "active" in the dashboard user widget. |
| `dashboard_activity_limit` | `10` | How many recent admin-log events the dashboard activity widget shows (clamped in code). |
| `user_resource` | core `AdminUserResource` | The resource that serialises users for the admin list. Point it at a subclass to append host columns without forking the Vue table. Must extend `AdminUserResource` or it is ignored. |
| `user_columns` | `[]` | Optional user-list columns to show. Empty is a privacy-clean table. Recognised tokens: `phone`, `sex`, `age`, `social`. Unknown tokens are ignored. |

The super-admin identity and the console route the operator is confined to live
under the `security.super_admin` and `auth.admin.console_route` keys (phases 1.4
and 2.3); those belong to the auth and admin-security references.

## Usage

### Navigation levels

Every visitor resolves to one navigation level, computed by
`LaraFoundryNavigation` from the authenticated user:

- `admin` - a super-admin (per `VisitorStatus::isAdmin`). Gets the operator-console menu.
- `tenant` - an authenticated user with an active company. Gets the tenant sidebar.
- `null` - a guest, or a user with no active company yet. Gets an empty menu.

The level is the single source of truth: it both builds the menu and, shipped as
`nav_level`, tells the frontend which shell to render. Because both come from the
same value, the menu and the shell cannot drift, and a host that wires the
navigation props but not the tenancy props still gets the right shell.

### Shared props

`LaraFoundryNavigation::sharedProps()` returns four lazy Inertia props:

| Prop | Value |
|------|-------|
| `navigation` | The filtered, sorted menu tree for the visitor's level (an array of item arrays; empty for guests). |
| `nav_level` | `'admin'`, `'tenant'` or `null` - the signal `LayoutSwitcher` keys off. |
| `visitor_status` | The identity-level status (`guest`, `authenticated`, `verified`, `admin`, `blocked`, `deleted`). |
| `impersonating` | `true` while the session is impersonating another user (drives the banner). |

### The page shell (LayoutSwitcher)

`LayoutSwitcher` is a persistent Inertia layout that picks the shell from
`nav_level`: `admin` renders `AdminLayout` (the operator console), `tenant`
renders `AppLayout` (the tenant app), anything else renders `AppBaseLayout` (the
bare shell). It is imported from the package's Vue barrel:

```js
import { LayoutSwitcher } from '@dmitryisaenko/larafoundry';
defineOptions({ layout: LayoutSwitcher });
```

Blocked and deleted users are logged out by `EnsureAccountIsActive` before a page
renders, so no shell handles them.

### Registering a host menu provider

A provider implements `MenuProviderInterface` and returns `MenuItem` DTOs. Add it
to the shared `MenuBuilder` in `AppServiceProvider::boot()`:

```php
use Dmitryisaenko\LaraFoundry\Navigation\Contracts\MenuProviderInterface;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuBuilder;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuItem;

class OrdersMenuProvider implements MenuProviderInterface
{
    public function getMenuItems(string $level): array
    {
        if (! $this->supports($level)) {
            return [];
        }

        return [
            new MenuItem(
                labelKey: 'Orders',                // an i18n KEY, translated in Vue
                route: 'orders.index',
                policy: 'orders.view',             // RBAC slug; null = always show
                icon: 'orders',                    // icon NAME, resolved to inline SVG
                order: 10,
                activePatterns: ['orders.*'],      // route-name wildcards for active state
            ),
        ];
    }

    public function supports(string $level): bool
    {
        return $level === 'tenant';
    }

    public function priority(): int
    {
        return 200;                                // provider merge order; lower runs first
    }
}
```

```php
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    $this->app->make(MenuBuilder::class)->addProvider(new OrdersMenuProvider);
}
```

The item's `labelKey` is an English-as-key i18n string, not a translated one: the
Vue layer runs `$t()` at render (decision D-nav-c), so a locale switch repaints
the menu live, with no reload. The `icon` is a name mapped to an inline SVG in Vue
(decision D-nav-f), never a published asset URL - this keeps icons out of
`vendor/` publishing. A `MenuItem` may carry a one-level `submenu` of child
`MenuItem`s.

### Growing the "My company" group

The core's `TenantMenuProvider` ships a collapsible "My company" group (added in
v0.22) holding Employees, Roles and Company settings, each guarded by its RBAC
slug. It is a PURE group - no own route or URL. When a host adds another "My
company" group from its own provider (for example carrying a Dashboard child),
the builder merges top-level pure groups that share a `labelKey` into one, so
core and host children land under a single parent instead of two identically
named groups. The merged group keeps the lowest `order` of the copies, and its
children are re-sorted by `order`, so a host child (order 10) and a core child
(order 80) interleave correctly.

## API reference

### `LaraFoundryNavigation` (host wiring helper)

| Method | Returns | Purpose |
|--------|---------|---------|
| `sharedProps()` | `array<string, Closure>` | The four Inertia props: `navigation`, `nav_level`, `visitor_status`, `impersonating`. |

The remaining methods (`menu`, `levelFor`, `impersonating`) are protected
internals; a host uses only `sharedProps()`.

### `MenuBuilder` (singleton)

| Method | Signature | Purpose |
|--------|-----------|---------|
| `addProvider` | `addProvider(MenuProviderInterface $provider): self` | Register a provider; providers are kept ordered by `priority()`. |
| `setPolicyChecker` | `setPolicyChecker(PolicyChecker $checker): self` | Swap the visibility gate (the core wires `RbacPolicyChecker`). |
| `build` | `build(string $level, ?Authenticatable $user = null): array` | Collect, merge groups, filter, sort, serialise. Memoised per `level:userId` for the request. |
| `flush` | `flush(): void` | Forget the per-request memo (tests, or a mid-request context change). |

The build pipeline is: collect items from every provider that `supports($level)`
-> merge same-label pure groups -> filter out hidden and policy-failing items
(recursively, dropping any pure group left empty) -> sort by `order` (recursively)
-> serialise each `MenuItem` to an array.

### `MenuProviderInterface`

| Method | Signature | Purpose |
|--------|-----------|---------|
| `getMenuItems` | `getMenuItems(string $level): array` | The items this provider contributes for `'admin'` or `'tenant'`. |
| `supports` | `supports(string $level): bool` | Whether this provider contributes to the level. |
| `priority` | `priority(): int` | Provider merge order (lower first); item `order` still sorts the final list. |

Filtering is the builder's job; a provider just declares its items. The builder
clones items before pruning their submenus, so returning cached or shared
instances is safe.

### `MenuItem` (DTO)

Constructor (named arguments):

| Argument | Type | Meaning |
|----------|------|---------|
| `labelKey` | `string` | i18n key (English-as-key), translated in Vue. |
| `route` | `?string` | Route name; resolved to a URL server-side. |
| `url` | `?string` | Explicit URL when there is no route name (wins over `route`). |
| `policy` | `?string` | Permission slug guarding visibility; `null` = always show. |
| `icon` | `?string` | Icon name, resolved to inline SVG in Vue. |
| `order` | `int` | Sort key, lower is higher (default `100`). |
| `visible` | `bool` | Hard hide regardless of policy (default `true`). |
| `submenu` | `array<MenuItem>` | Nested items, one level deep. |
| `meta` | `array` | Free-form metadata (badges, etc.). |
| `activePatterns` | `?array` | Route-name wildcards for active state; `null` defaults to `["{route}*"]`. |

Methods: `resolveUrl()` (explicit URL wins, else the named route; an unregistered
route yields `''` rather than throwing, so one unbuilt host route cannot blank the
menu), `isActive()` (route-name wildcard match; URL-only items are never
auto-active), `toArray()` (serialises, shipping the label KEY not a translation).

### `PolicyChecker` / `RbacPolicyChecker`

`PolicyChecker::check(Authenticatable $user, string $policy): bool` decides
whether a user may see an item guarded by a permission slug. The core binds it to
`RbacPolicyChecker`, which bridges to
`HasRolesAndPermissions::hasPermissionTo($slug, $activeCompany)` (phase 1.3) - the
same super-admin -> owner -> roles rule that guards the routes also decides the
menu. The active company is read from the tenancy trait so the check is scoped to
the user's context. The builder depends on the contract, not the trait, so a host
can rebind the rule.

### Core providers

- `AdminMenuProvider` (`priority 0`, level `admin`): Dashboard, Users, Companies,
  Activity log, Broadcasts, Support, Email templates, Legal pages, Payments,
  Settings. These carry NO permission slug - the whole zone already sits behind
  the `larafoundry.admin` (super-admin) gate, which is the authority.
- `TenantMenuProvider` (`priority 100`, level `tenant`): the "My company" group
  (Employees, Roles, Company settings), each child carrying its RBAC slug.

### Vue components (from the `@dmitryisaenko/larafoundry` barrel)

`LayoutSwitcher` (shell selector), `SidebarNav`, `NavItem`, `NavIcon`,
`MobileNav`. `MobileNav` reuses `SidebarNav` for the tree, so desktop and mobile
never drift; each label renders through `$t(item.labelKey)` and each active state
comes from the backend `item.active`.

## Security notes

- **Filtering is server-side, so hidden links never reach the client.** The
  builder drops items the user may not see (recursively, including submenus)
  before serialisation. Unlike a frontend permission filter, an unreachable link
  and its route are simply absent from the payload (decision D-nav-a).
- **A menu slug is checked with the route's own rule.** `RbacPolicyChecker` calls
  the same `hasPermissionTo($slug, $activeCompany)` the routes use, scoped to the
  active company, so the menu can never advertise a screen the route would deny.
- **A mis-wired host fails closed.** If the `User` model lacks the RBAC trait,
  `RbacPolicyChecker` returns `false` for any item that carries a policy - the
  item is hidden, not shown. An item with no policy and no policy checker passes.
- **Empty groups are dropped.** A pure group (no own route or URL) whose children
  are all filtered out is removed, so a bare member never sees an empty "My
  company" heading pointing nowhere.
- **The level is derived from auth, not the request.** `nav_level` comes from
  `VisitorStatus::isAdmin` and the active company resolved from the session, never
  from a request parameter, so a user cannot ask for a shell or menu above their
  station.
- **The console is a gated zone, not a menu convention.** Admin menu items carry
  no slug because `larafoundry.admin` gates every console route; the menu is a
  convenience over that gate, not a substitute for it.

## Testing

The navigation suite lives in `tests/Feature/Navigation/NavigationMenuTest.php`
and uses `RefreshDatabase` with the test `User` fixture and the core `Company`.
It covers:

- The admin menu for a super-admin (Dashboard first at `order` 5, plus Users,
  Companies, Activity log, Broadcasts, Support).
- An owner seeing every child of the "My company" group via owner bypass.
- A bare member seeing an empty menu - every "My company" child is filtered out
  and the empty pure group is dropped.
- A permission slug checked through the RBAC trait (`RbacPolicyChecker`).
- The shared props: a super-admin gets the admin navigation and `nav_level`
  `admin`; a guest gets an empty menu, `guest` status and a null level; and
  `impersonating` is `false` by default.

Run them with Pest:

```bash
composer test
```

## What changed from the early draft

If you arrived from the older [modules/navigation.md](modules/navigation.md), the
shipped design differs substantially:

| Early draft | Shipped |
|-------------|---------|
| `LayoutDataService` (~1000-line service) builds the menu | A `MenuBuilder` merges pluggable `MenuProviderInterface` classes; the host adds its own provider instead of editing one service |
| Labels baked with `__($label)` on the backend | Labels are i18n KEYS, translated in Vue via `$t()`, so a locale switch repaints without reload |
| Icons as `.svg` asset filenames | Icons are NAME strings resolved to inline SVG in Vue (keeps icons out of `vendor/` publishing) |
| Three user types (admin / owner / employee) | Two navigation levels (`admin` / `tenant`); owner-vs-employee is an RBAC filtering outcome, not a separate menu |
| Desktop two-tier header (module tabs + sub-page tabs) | One filtered tree per level; a collapsible "My company" group holds the core tenant screens (v0.22) |
| First Allowed Route (FAR) pattern, saved default page per company, breadcrumbs | Not part of this module in the shipped package |
| Menu chosen from `activeCompany` | Menu and shell both key off one `nav_level` signal, so they cannot disagree |
| Business modules (Orders, Warehouse, Production, ...) shipped as core menu items | The core ships only its own cross-cutting screens; domain modules are host providers |
