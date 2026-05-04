# Admin Companies Module

The financial control center for managing companies in a multi-tenant SaaS.

## Features

| Feature | Description |
|---------|-------------|
| Company Dashboard | Full overview: owner, subscription, payments, activity in one table |
| Subscription Engine | 5 states with priority-based calculation and admin filtering |
| Blocking System | 3 reasons with owner/employee message split |
| Ban Cascade | Owner ban → all employees blocked |
| Expiry Notifications | Automated warnings at 10d and 3d before expiry |
| Payment Model | Full lifecycle with events and audit trail |
| Blocked Pages | Context-aware Vue component with 3 configurations |
| Admin Filtering | Auto-discovery filters + multi-field search + sorting |

## Company Model

### Key Fields

```
companies table:
├── identity: id, uuid, name, slug, description, logo
├── location: country, contragent_allowed_countries (JSON)
├── business: activity_type, employees_count
├── subscription: plan_id, billing_period, trial_ends_at, subscription_ends_at, free_month_used_at
├── owner: created_by_id (FK → users)
├── contact: email, phone, phones (JSON), website, social_links (JSON)
├── details: contact_details (JSON), financial_details (JSON)
└── timestamps: created_at, updated_at, deleted_at (soft delete)
```

### Access Methods

```php
isOnTrial()              // trial_ends_at is future
hasActiveSubscription()  // subscription_ends_at is future
hasAccess()              // isOnTrial() OR hasActiveSubscription()
isInSetup()              // no plan, no trial, no subscription
```

### Relationships

- `createdBy()` → BelongsTo User (owner)
- `users()` → BelongsToMany User (via company_user pivot)
- `owners()` → BelongsToMany User (is_owner = true)
- `roles()` → HasMany Role
- `invitations()` → HasMany CompanyInvitation
- `payments()` → HasMany CompanyPayment

## Company-User Pivot Table

```
company_user:
├── company_id, user_id (unique together)
├── is_owner (boolean)
├── is_deleted (boolean, soft delete)
├── added_by_id (FK)
├── removal_requested_at, removal_requested_by
├── default_route (nullable)
└── created_at, updated_at
```

## Subscription Status Engine

### 5 States (Priority Order)

1. `never_activated` - no plan, no trial, no subscription (isInSetup)
2. `trial` - trial_ends_at is future
3. `expired` - subscription_ends_at is past or null
4. `expiring` - subscription_ends_at within 7 days
5. `active` - subscription_ends_at > 7 days in future

### Admin Panel Status Indicators

Each status has a color-coded badge in the admin companies table.

## Blocking System

### GetBlockStatusAction

Returns complete access status on every request:

```php
[
    'user_blocked'             => bool,
    'user_ban_reason'          => string|null,
    'company_blocked'          => bool,
    'company_block_reason'     => 'owner_banned' | 'first_payment_required' | 'payment_expired',
    'subscription_expires_soon' => bool,
    'days_until_expiry'        => int|null (0-10),
]
```

### Block Priority Levels

1. **User ban** (highest) - user personally banned by admin → `user.blocked` page
2. **Owner ban** - company owner banned → `company.payment.blocked?type=owner_banned`
3. **Payment issue** - no access → `company.payment.blocked?type=payment_required`

### Banned User Whitelist

Banned users can still access: support tickets, notifications, tutorials, language switch, logout, leave impersonation, switch companies.

### Per-Company Blocking

Block applies to specific company, not user account. User can switch to another company with active subscription.

## Subscription Expiry Notifications

### Command: `subscriptions:check-expiring`

Runs on cron schedule. Checks twice:
- 10 days before expiry (friendly reminder)
- 3 days before expiry (urgent warning)

### Flow

1. Find companies where `subscription_ends_at` = target date
2. Skip if no owner or owner is banned
3. Check unique notification code (`subscription_expiring_{days}d_{company_id}`)
4. If not exists: create notification with all locale translations
5. Attach notification to company owner

### Duplicate Prevention

Unique code per notification ensures cron-safe execution. Same notification never sent twice.

## CompanyPayment Model

### Fields

```
company_payments table:
├── company_id, user_id (FKs)
├── plan_id, billing_period (monthly/yearly)
├── amount (decimal), currency
├── discount_amount, discount_reason, promo_code_id
├── payment_status (pending/success/failed)
├── payment_method, payment_response (JSON)
├── paid_at (datetime)
├── period_start, period_end (dates)
└── created_at, updated_at
```

### State Methods

```php
markAsPaid(?array $response)    // status=success, paid_at=now()
markAsFailed(?array $response)  // status=failed
getTotalAmount()                // amount - discount_amount
isSuccessful()                  // status === 'success'
isPending()                     // status === 'pending'
isFailed()                      // status === 'failed'
```

### Events

- `CompanyPaymentProcessed` - fires on payment with full context (amount, currency, plan, period, discount)

## Frontend

### CompanyBlocked.vue

Single component, 3 configurations:

| blockType | Icon | Message | CTA |
|-----------|------|---------|-----|
| user_banned | Ban | Account blocked | Contact support |
| payment_required | Credit card | Subscription expired | Go to billing |
| payment_required_non_owner | Credit card | Contact administrator | Logout |

### Inertia Shared Props

`block_status` shared on every page via `HandleInertiaRequests` middleware. Frontend can show warning banners, disable features, or display countdowns without extra API calls.

### CompaniesTable.vue (Admin)

680+ lines. Features:
- Subscription status filtering (5 states)
- Sorting by creation date, subscription expiry, employees count, payments sum
- Owner blocked badge
- Tooltips with employee details and payment history
- Responsive (desktop table + mobile cards)

## Admin Filtering

### AdminCompaniesFilter

Auto-discovery pattern. Methods:
- `subscription_status()` - 5 states with date-based SQL
- `plan_id()` - exact match
- `country()` - exact match
- `date_from()` / `date_to()` - created_at range
- `search_string()` - company name OR owner email

### AdminCompaniesFilterRequest

Validation: subscription_status (enum), plan_id (enum), country (string), date_from/to (dates), search_string (max:255), sort_by (enum), sort_order (asc/desc)

### Quick Search

`GET /admin/companies/search` - JSON endpoint, 15 results max, by company name or owner email.

## Routes

```
GET  /admin/companies         → CompanyController@index    (admin.companies.index)
GET  /admin/companies/search  → CompanyController@search   (admin.companies.search)
```

Middleware: AdminAccess, 2fa

## Events

| Event | Trigger | Log Properties |
|-------|---------|---------------|
| CompanyCreate | Company created | company_id, name, slug, has_access, user info |
| CompanyDeleted | Company deleted | company_id, name, slug, deleted_at, user info |
| CompanyPaymentProcessed | Payment processed | company, payment, plan, amount, period |
| CompanyTrialActivated | Trial started | company, plan, billing_period, trial_ends_at |
| CompanyInvitationSent | Invitation sent | company, invitation, email, role, user info |

## Jobs

| Job | Purpose |
|-----|---------|
| SendCompanyCreatedJob | Email notification to owner |
| NotifyOwnerAboutCompanyCreated | In-app notification |
| NotifyOwnerAboutCompanyDeleted | In-app + email notification |
| SendCompanyInvitationJob | Email + in-app notification for invitations |
| CloneCompanyRolesJob | Copy role templates to new company |

## Observer

CompanyObserver handles:
- `creating()` - set default contragent_allowed_countries from country config
- `created()` - dispatch SendCompanyCreatedJob, NotifyOwnerAboutCompanyCreated, fire CompanyCreate event
- `deleted()` - fire CompanyDeleted event with data snapshot

## File Structure

```
app/
├── Actions/
│   ├── Company/SwitchCompanyAction.php
│   └── GetBlockStatusAction.php
├── Console/Commands/
│   └── CheckExpiringSubscriptions.php
├── Events/Company/
│   ├── CompanyCreate.php
│   ├── CompanyDeleted.php
│   ├── CompanyInvitationSent.php
│   ├── CompanyPaymentProcessed.php
│   └── CompanyTrialActivated.php
├── Gates/
│   ├── CompanyGates.php
│   └── UserBlockedGates.php
├── Http/
│   ├── Controllers/Admin/CompanyController.php
│   ├── Controllers/Company/CompanyPaymentController.php
│   ├── Filters/AdminCompaniesFilter.php
│   ├── Middleware/CheckAccessMiddleware.php
│   ├── Middleware/SetActiveCompanyMiddleware.php
│   ├── Middleware/CheckCompanyAccess.php
│   ├── Requests/Admin/AdminCompaniesFilterRequest.php
│   └── Resources/Admin/AdminCompanyResource.php
├── Jobs/Company/
│   ├── SendCompanyCreatedJob.php
│   ├── NotifyOwnerAboutCompanyCreated.php
│   ├── NotifyOwnerAboutCompanyDeleted.php
│   ├── SendCompanyInvitationJob.php
│   └── CloneCompanyRolesJob.php
├── Models/
│   ├── Company.php
│   ├── CompanyPayment.php
│   └── CompanyInvitation.php
├── Notifications/
│   ├── CompanyCreatedNotification.php
│   ├── CompanyDeletedNotification.php
│   └── CompanyInvitationNotification.php
└── Observers/
    └── CompanyObserver.php

resources/js/pages/
├── admin/companies/
│   ├── IndexCompanies.vue
│   └── components/CompaniesTable.vue
├── auth/UserBlocked.vue
└── company/CompanyBlocked.vue

database/migrations/
├── 0001_01_01_000003_create_companies_table.php
├── 2025_11_07_222919_create_company_invitations_table.php
└── 2025_11_07_222937_create_company_payments_table.php
```

## Testing

Tested areas:
- Subscription status calculation (5 states)
- hasAccess() with trial + subscription combinations
- Ban cascade (owner blocked → employee blocked)
- Company switch when one company blocked
- Payment model state transitions
- CompanyPaymentProcessed event dispatch
- Expiry notification creation + duplicate prevention
- Banned owner notification skip
- Admin filtering by all parameters
- Quick search by name and email
