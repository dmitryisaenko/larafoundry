# Admin Users Module

The command center for managing users in a multi-tenant SaaS application. Built for [Kohana.io](https://kohana.io) (production CRM/ERP), extracted into LaraFoundry.

## Overview

| Feature | Implementation |
|---------|---------------|
| CRUD | FormRequest validation + Eloquent Attribute mutators + UserResource |
| Ban system | Config-driven reasons + events + queued jobs + middleware enforcement |
| Impersonation | lab404/laravel-impersonate + 2FA + frontend awareness |
| Activity logging | Spatie ActivityLog + CustomActivity model + async geo + device fingerprinting |
| Search | Multi-field (ID, name, email, phone, full name combinations) |
| Filtering | 8 auto-discovered filter methods via abstract Filter class |

## CRUD

### Create

- **Validation**: `AdminUserStoreRequest` - name, email, password (configurable min length), optional profile fields, social links array
- **Password hashing**: Eloquent `Attribute` mutator on the User model - controllers never see raw passwords
- **Social links**: Validated array (URL format + social network from config)

### Read

- **Index**: Paginated user list with `AdminUsersFilter` applied via `Filterable` scope
- **Transformation**: `UserResource` handles all data formatting (activity status, country translation, social links, company counts, block status)
- **Pagination**: Config-driven page size (`config('own.admin_users_per_page')`)

### Admin Table - Data Per User

| Column | Data | Details |
|--------|------|---------|
| ID | User ID | Numeric identifier |
| Avatar | Profile image | Falls back to default placeholder |
| Name | Full name | lastname + name, with block/delete badge if applicable |
| Email | Email address | With verification status icon |
| Phone | Phone number | With verification status icon |
| Social links | Network icons | Clickable links to configured social profiles |
| Registration | Created date | Formatted as d-m-Y |
| Last activity | Activity timestamp | "5 min ago", "long_ago" (with warning style), or "Never" |
| Companies | Owned / Employee | Count of owned companies and employee companies |
| Country | Localized name | Translated from config |
| Sex | Gender label | "Man.", "Fem.", or empty |
| Age | Calculated age | From birth_date |
| Block info | Reason + timestamp | "1. SPAM (3 days ago)" - shown only if blocked |
| Delete info | Timestamp | "(3 days ago)" - shown only if deleted |
| Actions | Buttons | Create ticket, view logs, edit, block/unblock, delete/undelete, impersonate |

**Row styling**: Blocked users and deleted users get distinct visual styles. Action buttons change based on status (block↔unblock, delete↔restore). Edit and impersonate buttons are hidden for blocked/deleted users.

### Update

- **Social links**: Database transaction - delete existing, create new (atomic, no orphans)
- **Email uniqueness**: `Rule::unique()->ignore($currentUser->id)`
- **Return URL**: Admin redirected back to their origin page

### Delete / Restore

- **Soft delete**: `user_deleted_at` timestamp (not Laravel's SoftDeletes trait)
- **Cleanup**: Deleting clears block status (`user_blocked_at`, `user_blocked_status` set to null)
- **Restore**: Clear `user_deleted_at` timestamp

## Ban System

### Block Reasons

Config-driven in `config/own.php`:

```php
'block_codes' => [
    1 => 'SPAM',
    2 => 'Violation of the rules of service',
    3 => 'Inappropriate behavior',
    4 => 'Fake account',
]
```

### Block Flow

1. Admin selects reason, clicks "Block"
2. `user_blocked_at` = now(), `user_blocked_status` = reason code
3. `UserBlocked` event fired (captured by ActivityLogServiceProvider)
4. `NotifyUserAboutBlocked` job dispatched to queue

### Access Enforcement (CheckAccessMiddleware)

**Level 1 - User ban:**
Redirects to blocked page. Whitelisted routes: support tickets, notifications, tutorials, logout, language switch, leave impersonation.

**Level 2 - Owner ban cascade:**
All employees of the banned owner's company are blocked. Different redirect, different message.

**Level 3 - Payment expired:**
Users can access payment pages and settings only.

### Blocked User Page

Shows ban reason (translated) and ban date. Gated: only users with `user_blocked_at !== null` can access.

### Unblock

Clears timestamps, fires `UserUnblocked` event, dispatches `NotifyUserAboutUnblocked` job.

## Impersonation ("Follow")

### Routes

| Method | Route | Action |
|--------|-------|--------|
| POST | `/admin/impersonate/{user}` | Start impersonation |
| POST | `/admin/impersonate/leave` | End impersonation |

### Security

- `AdminAccess` middleware required
- 2FA verification required
- Event logged in activity log
- "Leave" route whitelisted for banned users (admin can exit cleanly)
- Button hidden for blocked/deleted users

### Frontend

Impersonation state shared via HandleInertiaRequests:

```php
'impersonate' => ['isActive' => $impersonate->isImpersonating()]
```

Vue frontend can display warning bar when active.

## Activity Logging

### CustomActivity Model

Extends Spatie's Activity model with additional fields:

| Category | Fields |
|----------|--------|
| Core | log_name, description, subject_type, subject_id, causer_id |
| Geo | geo_country, geo_city, geo_updated_at |
| Device | user_device_type, user_device_name, user_os, user_browser |
| Network | user_ip, user_agent |
| Request | route_name, request_method, full_url, response_code |
| Status | is_successful |

### Event Registration

`ActivityLogServiceProvider` registers listeners for 30+ system events. Each event implements `getLogProperties()` for custom data.

### Async Geo Lookup

`RetrieveActivityGeoData` queued job resolves IP to country/city. Request not slowed by external API.

### Admin UI

**User logs**: `GET /admin/logs/{user}?hours=1` - time range 1h-72h, paginated (50/page)
**General logs**: `GET /admin/logs?hours=24` - system-wide, paginated (100/page)

Interactive tooltips: hover IP for device/geo info, hover action for event details.

## Search & Filtering

### Multi-Field Search

Searches across: user ID, name, lastname, middlename, email, phone, and full name combinations (lastname+name, name+lastname, with middlename variants).

### Filter Methods (AdminUsersFilter)

| Filter | Type | Logic |
|--------|------|-------|
| search_string | string | Multi-field search with name combinations |
| age_from | integer (16-100) | birth_date <= now - age years |
| age_to | integer (16-100) | birth_date >= now - age years |
| country | string | Exact match, validated against config |
| registrated | enum | today, this_month, this_year |
| email_verificated | enum | verificated, unverificated |
| phone_verificated | enum | verificated, unverificated |
| recent_activity | enum | more, less_or_equal (threshold from config) |
| sex | enum | m, f |

### Real-Time Search

`GET /admin/users/search` returns JSON. Frontend shows results as user types.

### Validation

`AdminUserFilterRequest` + `HasUserFilterRules` trait validates all filter parameters.

## File Structure

```
app/
├── Http/
│   ├── Controllers/Admin/
│   │   ├── UserController.php          # CRUD + block/unblock + search
│   │   ├── ImpersonateController.php   # take/leave impersonation
│   │   └── ActivityLogController.php   # user logs + general logs
│   ├── Requests/Admin/
│   │   ├── AdminUserStoreRequest.php
│   │   ├── AdminUserUpdateRequest.php
│   │   └── AdminUserFilterRequest.php
│   ├── Filters/
│   │   ├── Filter.php                  # Abstract base class
│   │   └── AdminUsersFilter.php        # User-specific filters
│   ├── Resources/Admin/
│   │   ├── UserResource.php
│   │   └── ActivityLogResource.php
│   └── Middleware/
│       └── CheckAccessMiddleware.php   # 3-level access enforcement
├── Events/User/
│   ├── UserBlocked.php
│   └── UserUnblocked.php
├── Jobs/User/
│   ├── NotifyUserAboutBlocked.php
│   └── NotifyUserAboutUnblocked.php
├── Models/
│   ├── User.php                        # Impersonate trait + Attribute mutators
│   └── CustomActivity.php              # Extended Spatie Activity
├── Services/
│   └── ActivityLogService.php
└── Providers/
    └── ActivityLogServiceProvider.php  # Event → log mapping

resources/js/Pages/admin/
├── users/
│   ├── Users.vue                       # Index with search + filters
│   ├── CreateUser.vue
│   └── EditUser.vue
├── components/users/
│   ├── AdminUsersSearchResults.vue     # Real-time search dropdown
│   └── AdminUsersTableBtnsRow.vue      # Action buttons (edit, follow, block, delete)
└── logs/
    └── UserLogs.vue                    # Activity timeline with tooltips
```

## Testing

- CRUD: create/update/delete with valid and invalid data
- Ban cascade: block owner → verify employee blocked
- Access whitelists: banned user can access support tickets
- Impersonation: follow/leave, security requirements
- Events: UserBlocked/UserUnblocked dispatched
- Jobs: notification jobs pushed to queue
- Filters: each filter with valid input, combined filters, validation errors
- Search: multi-field matching, real-time endpoint

All tests use real HTTP requests via Pest v4. No middleware mocking.
