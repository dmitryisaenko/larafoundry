# Navigation Module

## Overview

LaraFoundry's Navigation module provides a dynamic, permission-aware menu system for multi-tenant Laravel + Inertia + Vue SaaS applications. It builds navigation menus on every request based on the current user's type, company role, and granted permissions.

## Features

- Dynamic per-request menu building via LayoutDataService
- Three user types with distinct menu structures (admin, owner, employee)
- Permission filtering at module-level and sub-page-level
- First Allowed Route (FAR) pattern - zero 403 pages
- User-configurable default landing page per company
- Sub-menu routing with per-module default pages
- Desktop two-tier header navigation
- Mobile hamburger menu with collapsible sections
- GPU-accelerated CSS transitions
- Full i18n support via `__()` helper
- Breadcrumb generation from active navigation items
- Notification badges (support tickets, unread count)
- Comprehensive Pest test suite

## User Types & Menus

| User type | Menu | Modules |
|-----------|------|---------|
| Admin | Admin panel | Users, Companies, Payments, Notifications, Support |
| Owner | Business modules | All 8 modules + company management sidebar |
| Employee | Filtered business | Only modules with explicit permissions |

## Architecture

### Menu Building Flow

```
Request
  |
  v
LayoutController::getLayout()
  |
  v
LayoutDataService
  |
  ├── getNav('upLevel')          → Desktop top-level module tabs
  ├── getNav('bottomLevel')      → Desktop sub-page tabs for current module
  ├── getNav('myCompanySidebar') → Company management sidebar
  ├── getNav('mobile')           → Full hierarchical tree for mobile
  ├── getFirstAllowedRouteName() → Smart redirect target
  |
  └── Each method filters items via:
        checkUserAndCompanyPolicy($policyName)
          ├── Admin → always allowed
          ├── 'public' → any authenticated user
          └── else → $user->hasPermissionTo($policy, $company)
  |
  v
HandleInertiaRequests (middleware)
  |── Shares as lazy-loaded Inertia prop
  |
  v
Vue frontend renders pre-filtered arrays
```

### Permission Filtering

```php
private function checkUserAndCompanyPolicy($policyName): bool
{
    if (self::isAdmin()) return true;
    if (empty($policyName)) return false;
    if ($policyName === 'public') return auth()->check();

    $user = auth()->user();
    $company = $user->activeCompany;

    if ($user->isOwnerOf($company)) return true;

    return $user->hasPermissionTo($policyName, $company);
}
```

### Menu Item Structure

```php
[
    'linkName'        => __('Orders'),
    'linkUrl'         => route('orders.incoming'),
    'policyName'      => 'orders.view',
    'routeName'       => 'orders',
    'linkIconSvg'     => 'icon_orders.svg',
    'isLinkActive'    => Route::currentRouteNamed('orders.*'),
    'companyItem'     => true,
]
```

### Sub-Menu Routing

Each module maps to a default sub-page:

```php
'orders'       => 'orders.incoming',
'warehouse'    => 'warehouse.products',
'accounting'   => 'accounting.funds_movement',
'contragents'  => 'contragents.customers',
'production'   => 'production.main_workshop',
```

Sub-routes have independent permission checks:

```php
// Warehouse sub-routes
['routeName' => 'warehouse.report',      'policyName' => 'warehouse.view'],
['routeName' => 'warehouse.products',    'policyName' => 'warehouse.view'],
['routeName' => 'warehouse.consumables', 'policyName' => 'warehouse.view'],
['routeName' => 'warehouse.settings',    'policyName' => 'warehouse.manageCategories'],
```

## First Allowed Route (FAR) Pattern

Eliminates 403 pages entirely. Resolution order:

1. Admin → `admin.dashboard.index`
2. No company/role → `notifications.index`
3. Saved default route (if still accessible)
4. First accessible menu item → first accessible sub-page
5. Fallback → `notifications.index`

Used in four scenarios:
- After login
- After 403 access denial
- After company switch
- Home link target

### User-Configurable Default Page

Stored per company in `company_user` pivot table. Auto-cleared if permissions change.

## File Structure

```
app/
  Http/
    Controllers/
      LayoutController.php             # Returns layout data for Inertia
    Middleware/
      HandleInertiaRequests.php        # Shares layout as Inertia prop
  Services/
    LayoutDataService.php              # Core menu building logic (~1000 lines)
  Actions/
    Profile/
      GetDefaultBottomLevelRouteNameAction.php  # Module-to-default-subpage mapping

resources/js/
  layouts/
    AuthLayout.vue                     # Main layout with menu state management
    AdminLayout.vue                    # Admin-specific layout
    GuestLayout.vue                    # Guest layout
  layouts/app/
    AppHeaderDesktopLayout.vue         # Desktop two-tier navigation
    AppPulloutMobilemenuLayout.vue     # Mobile hamburger pullout
    AppPulloutMenuRightLayout.vue      # Right-side user menu
    AppMobileNavLink.vue               # Mobile nav item (parent/child)
    HeaderNavLink.vue                  # Desktop nav item
```

## Data Passed to Frontend

```php
'layoutData' => [
    'homeRoute'                  => route('...'),
    'navDesktopUpLevel'          => [...],  // Top-level module tabs
    'navDesktopBottomLevel'      => [...],  // Sub-page tabs
    'navDesktopMycompanySidebar' => [...],  // Company sidebar
    'navMobile'                  => [...],  // Full mobile tree
    'defaultRoute'               => '...',  // Saved default route name
    'breadcrumbsString'          => '...',  // Breadcrumb trail
]
```

### Mobile Tree Structure

```js
[
    {
        linkName: "Orders",
        linkUrl: "/orders/incoming",
        linkActive: true,
        linkIconUrl: "/icons/icon_orders.svg",
        child: [
            { linkName: "Report", linkUrl: "/orders/report", linkActive: false },
            { linkName: "Incoming", linkUrl: "/orders/incoming", linkActive: true },
        ]
    }
]
```

## Frontend

### Desktop

Two-tier header: top bar for modules, bottom bar for sub-pages. Uses Inertia `<Link>` components. Active states from backend `linkActive` prop.

### Mobile

Hamburger button opens slide-out pullout from left. Collapsible parent/child sections. Settings mode for default page selection. GPU-accelerated transitions.

### State Management

No Vuex/Pinia. Reactive refs in layout component + event emits for communication.

## Testing

Full Pest test coverage:
- Admin sees admin panel only
- Owner sees all 8 business modules
- Employee sees only modules with explicit permissions
- Sub-level permission filtering
- Default route save/clear/fallback
- Company switch recalculation
- Hidden items (notifications, support) accessible via URL
- Mobile tree matches desktop permissions
