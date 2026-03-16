# Multi-Tenancy & Authorization Module

> Production-grade multi-tenancy with automatic data isolation, config-driven permissions, and a 5-level authorization hierarchy for Laravel SaaS applications.

## Overview

The Multi-Tenancy module provides everything needed to run a multi-company SaaS where each company has its own data, roles, and permissions - all in a single database. Company owners manage their teams through a web UI without touching code.

This module is extracted from **Kohana.io**, a production SaaS CRM/ERP, and is battle-tested with real companies and real employees.

**Tech stack:** Laravel 12, Inertia.js v2, Vue 3, Pest PHP

---

## Features

### 1. Automatic Data Isolation

The `BelongsToCompany` trait adds a global scope to any Eloquent model, automatically filtering all queries by the current user's active company.

```php
use App\Models\Traits\BelongsToCompany;

class Order extends Model
{
    use BelongsToCompany;
}
```

**Queries are automatically scoped:**
```php
Order::query()->paginate(20);
// SELECT * FROM orders WHERE company_id = 5 LIMIT 20
```

**Bypass for admin:**
```php
Order::forAdmin()->latest()->get();    // All companies
Order::forCompany(7)->get();           // Specific company
```

**Key files:**
- `app/Models/Traits/BelongsToCompany.php`

---

### 2. Permission System

100+ permissions organized into 20+ modules, all defined in a single config file.

**Configuration:**
```php
// config/roles-and-permissions.php
'permissions' => [
    'orders' => [
        'label' => 'Orders',
        'permissions' => [
            'orders.view' => 'View orders',
            'orders.create' => 'Create orders',
            'orders.update' => 'Update orders',
            'orders.delete' => 'Delete orders',
            'orders.approve' => 'Approve orders',
            'orders.status_change' => 'Change order status',
            'orders.export' => 'Export orders',
        ],
    ],
    'warehouse' => [ /* ... */ ],
    'production' => [ /* ... */ ],
    'contragents' => [ /* ... */ ],
    'accounting' => [ /* ... */ ],
    // 20+ modules
],
```

**Auto-registration:**

`AuthServiceProvider` reads the config and registers a Laravel Gate for each permission automatically. Adding a new permission = adding one line to config + running `php artisan permissions:sync`.

**Key files:**
- `config/roles-and-permissions.php`
- `app/Providers/AuthServiceProvider.php`
- `app/Console/Commands/SyncPermissions.php`
- `app/Models/Permission.php`

---

### 3. Gate Classes

Complex authorization logic is organized into dedicated Gate classes (one per module):

| Gate Class | Purpose |
|------------|---------|
| `CompanyGates` | Company CRUD, settings access |
| `EmployeeGates` | Invite, remove, assign roles, grant permissions |
| `RoleGates` | Role CRUD, manage permissions |
| `ContragentGates` | View, create, update, delete contragents |
| `WarehouseGates` | Warehouse operations authorization |
| `ProductionGates` | Production order authorization |
| `ImprovementGates` | Feature request authorization |
| `UserBlockedGates` | Blocked user restrictions |

**Example - EmployeeGates:**
```php
Gate::define('employees.remove', function (User $user, User $employee) {
    $company = $user->getActiveCompany();

    if ($user->id === $employee->id) return false;
    if ($employee->isOwnerOf($company)) return false;
    if (!$employee->companies->contains($company)) return false;

    if ($user->isOwnerOf($company)) return true;

    return $user->hasPermissionTo('company.employees.remove', $company);
});
```

**Key files:**
- `app/Gates/CompanyGates.php`
- `app/Gates/EmployeeGates.php`
- `app/Gates/RoleGates.php`
- `app/Gates/ContragentGates.php`
- `app/Gates/WarehouseGates.php`
- `app/Gates/ProductionGates.php`

---

### 4. Permission Hierarchy (5 Levels)

Permissions are checked in strict priority order:

| Level | Check | Result |
|-------|-------|--------|
| 1 | Super Admin | Always `true` |
| 2 | Company Owner | Always `true` for their company |
| 3 | Revoked | `false` (overrides roles) |
| 4 | Individual Grant | `true` (overrides roles) |
| 5 | Role-based | From assigned company roles |

**Key method:** `User::hasPermissionTo(string $slug, Company|int|null $company)`

**Override mechanism:**

The `user_permissions` pivot table has an `is_revoked` boolean:
- `is_revoked = true` - Permission explicitly blocked (overrides any role)
- `is_revoked = false` - Permission explicitly granted (overrides roles)

**Key files:**
- `app/Models/Traits/HasRolesAndPermissions.php`

---

### 5. Role Management

**Role types:**

| Type | Scope | Editable | Example |
|------|-------|----------|---------|
| Global | System-wide | No | `super_admin`, `authenticated` |
| Template | Cloned to companies | Yes (in company) | `manager`, `accountant` |
| Custom | Company-specific | Yes | User-created roles |

**5 role templates cloned on company creation:**
- Manager - orders, contragents, production, dashboard
- Accountant - accounting, financial reports, payments
- Storekeeper - warehouse, inventory, assembly
- Logistician - logistics, delivery, waybills
- Worker - production tasks

**Company owner capabilities (from UI):**
- Edit template roles (add/remove permissions)
- Create custom roles
- Delete custom roles (if no users assigned)
- Assign multiple roles to one employee
- Override permissions per individual employee

**Key files:**
- `app/Models/Role.php`
- `config/roles-and-permissions.php` (role_templates section)

---

### 6. Middleware Stack

| Order | Middleware | Purpose |
|-------|-----------|---------|
| 1 | `HandleInertiaRequests` | SSR data |
| 2 | `SetActiveCompanyMiddleware` | Resolve tenant context |
| 3 | `UpdateLastSessionActivity` | Activity tracking |
| 4 | `SetLocale` | i18n |
| 5 | `EnsureEmailIsVerified` | Block unverified |
| 6 | `CheckPinLockMiddleware` | PIN lock (from auth module) |
| 7 | `CheckAccessMiddleware` | Ban + payment status |
| 8 | `CheckSessionExists` | Session validation |

**SetActiveCompanyMiddleware** - Sets active company with priority: owned > employee. Validates company still belongs to user on each request. Auto-switches if company is deleted.

**CheckAccessMiddleware** - Three checks: user ban, owner ban (blocks entire company), payment status (trial/subscription). Banned users can still access support tickets.

**CheckCompanyAccess** - Owner-only route protection for `my_company.*` routes.

**Key files:**
- `app/Http/Middleware/SetActiveCompanyMiddleware.php`
- `app/Http/Middleware/CheckAccessMiddleware.php`
- `app/Http/Middleware/CheckCompanyAccess.php`

---

### 7. Permission-Aware Navigation

Menu items are filtered by permissions using `checkUserAndCompanyPolicy()`:

```php
// Each menu item has a policyName
['linkName' => 'Orders', 'policyName' => 'orders.view', 'routeName' => 'orders']
['linkName' => 'Warehouse', 'policyName' => 'warehouse.view', 'routeName' => 'warehouse']
```

**Policy check logic:**
1. Admin sees everything
2. Empty policyName = hidden (security default)
3. `'public'` = visible to all authenticated users (Profile, Notifications, Support)
4. Owner sees all company modules
5. Employee - check `hasPermissionTo()`

**Key files:**
- `app/Services/LayoutDataService.php`

---

### 8. First Allowed Route (FAR)

Eliminates 403 pages. When a user can't access a route, they're redirected to the first page they CAN access.

**Resolution order:**
1. Admin -> `admin.dashboard.index`
2. No company -> `notifications.index`
3. User's saved default page (if still accessible)
4. First accessible menu item
5. Fallback: `notifications.index`

**Used in 4 places:**
- After login (landing page)
- After 403 (redirect instead of error)
- After company switch (permissions may differ)
- Sidebar "Home" link

**Key files:**
- `app/Services/LayoutDataService.php` (`getFirstAllowedRouteName()`)
- `bootstrap/app.php` (exception handler)

---

## Database Schema

```sql
-- Tenant entities
companies: id, name, created_by_id, trial_ends_at, subscription_ends_at, deleted_at

-- User-company relationship
company_user: user_id, company_id, is_owner, is_deleted, added_by_id,
              removal_requested_at, removal_requested_by, default_route

-- Role system
roles: id, name, slug, company_id, is_global, is_template, is_custom,
       description, created_by_id

-- Permission system
permissions: id, name, slug, module, description

-- Pivot tables
role_permissions: role_id, permission_id
user_roles: user_id, role_id, company_id, assigned_by_id
user_permissions: user_id, permission_id, company_id, is_revoked, granted_by_id
```

---

## Artisan Commands

```bash
# Sync permissions from config to database
php artisan permissions:sync

# Fresh sync (delete all, re-create)
php artisan permissions:sync --fresh

# Cleanup orphaned permissions/roles
php artisan permissions:sync --cleanup
```

---

## Test Coverage

19 test files using Pest PHP:

| Test File | Coverage |
|-----------|----------|
| `RolesAndPermissionsTest` | Permission hierarchy, gates integration, wildcard matching |
| `RoleGatesTest` | All role authorization gates (viewAny, create, update, delete, managePermissions) |
| `MenuDisplayTest` | Menu visibility based on roles/permissions, FAR routing |
| `SetActiveCompanyMiddlewareTest` | Tenant context resolution, priority, edge cases |
| `CheckAccessMiddlewareTest` | Ban status, payment status, allowed routes |
| `CompanyOwnerAccessTest` | Owner vs employee route access |
| `EmployeeRolesAndPermissionsTest` | Role assignment, permission grants/revokes, activity logging |
| `RoleManagementTest` | Complete role CRUD and authorization |
| `CompanySwitchTest` | Company switching and AuthUserDataService |

---

## Dependencies

- Laravel 12 (native Gates, no external permission packages)
- Inertia.js v2 + Vue 3 (admin UI for role/permission management)
- Pest PHP v4 (testing)
